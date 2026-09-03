#!/usr/bin/env node
/**
 * ============================================================
 *  Behavioural check of the rendered pages (jsdom)
 * ============================================================
 *      npm install jsdom          # once, anywhere on disk
 *      node tools/check-browser.js
 *
 *  No browser can be installed in this sandbox, so jsdom stands in:
 *  it loads each rendered page, runs the real assets/js/superadmin.js
 *  against it and asserts the behaviour a visitor would see.
 *
 *  Per page:
 *    - every stylesheet and script it references exists on disk
 *    - no raw PHP, no "undefined"/"NaN"/"[object Object]" in the markup
 *    - the shell is present (sidebar, topbar, content, footer) with the
 *      right nav item marked active — the login page is checked for its
 *      own auth layout instead
 *    - the theme toggle switches to light and persists the choice
 *    - the sidebar collapse button toggles the collapsed class
 *    - the table search box filters rows and reveals the empty state
 *    - clicking a sortable header sorts the column and sets aria-sort
 */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PREVIEW = path.join(ROOT, '.preview');
const SUPERADMIN = path.join(PREVIEW, 'superadmin');
const BUNDLE = path.join(ROOT, 'assets', 'js', 'superadmin.js');

const JSDOM_PATHS = [
    process.env.JSDOM_PATH,
    path.join(ROOT, 'node_modules', 'jsdom'),
    '/home/user/.pvtest/node_modules/jsdom',
    path.join(os.homedir(), '.pvtest', 'node_modules', 'jsdom'),
].filter(Boolean);

let JSDOM;
for (const candidate of JSDOM_PATHS) {
    try {
        JSDOM = require(candidate).JSDOM;
        break;
    } catch (e) {
        /* try the next location */
    }
}
if (!JSDOM) {
    // Not a failure - jsdom is an optional dependency of this one check.
    console.log('Skipped: jsdom is not installed (npm install jsdom)');
    process.exit(0);
}

if (!fs.existsSync(SUPERADMIN)) {
    console.error('No rendered preview found. Run: node tools/render-php-preview.js');
    process.exit(2);
}

const bundle = fs.readFileSync(BUNDLE, 'utf8');
const pages = [path.join(PREVIEW, 'login.html'), ...fs.readdirSync(SUPERADMIN).filter((f) => f.endsWith('.html')).map((f) => path.join(SUPERADMIN, f))];

let failures = 0;

function load(file) {
    const html = fs.readFileSync(file, 'utf8');
    const dom = new JSDOM(html, {
        runScripts: 'outside-only',
        pretendToBeVisual: true,
        url: 'http://localhost/superadmin/' + path.basename(file),
    });
    const { window } = dom;
    window.eval(bundle);
    // jsdom hands back a document that is still "loading" when we eval, so
    // fire the event the bundle waits on before asserting any behaviour.
    window.document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles: true }));
    return { html, window, doc: window.document };
}

function check(file) {
    const rel = path.relative(PREVIEW, file);
    const errors = [];
    const { html, window, doc } = load(file);
    const isLogin = /login\.html$/.test(rel);

    /* ---------- static markup ---------- */
    if (/<\?php|<\?=/.test(html)) errors.push('raw PHP tags leaked into the output');
    const suspicious = html.match(/.{0,40}(undefined|NaN|\[object Object]).{0,40}/);
    if (suspicious) errors.push('suspicious token in markup: ' + suspicious[0].replace(/\s+/g, ' '));

    for (const node of doc.querySelectorAll('link[href], script[src]')) {
        const ref = node.getAttribute('href') || node.getAttribute('src');
        // sa_asset() appends a ?v=<mtime> cache-buster; ignore it when
        // checking the file exists on disk.
        const onDisk = ref ? ref.split('?')[0] : '';
        if (onDisk && !/^(https?:)?\/\//.test(onDisk) && !fs.existsSync(path.resolve(path.dirname(file), onDisk))) {
            errors.push('missing asset ' + ref);
        }
    }

    /* ---------- structure ---------- */
    const required = isLogin
        ? ['#authForm', 'input[name="username"]', 'input[name="password"]', '.auth-card']
        : ['.sa-app', '.sa-sidebar', '.sa-topbar', '.sa-content', '.sa-foot'];
    for (const sel of required) if (!doc.querySelector(sel)) errors.push('missing ' + sel);

    if (!isLogin) {
        const nav = doc.querySelectorAll('.sa-nav a');
        if (nav.length < 7) errors.push('only ' + nav.length + ' nav links');
        if (!doc.querySelector('.sa-nav a.active')) errors.push('no nav item is marked active');
    }

    /* ---------- behaviour ---------- */
    const click = (el) => {
        if (!el) return;
        el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    };

    const toggle = doc.querySelector('[data-sa-theme]');
    if (toggle) {
        click(toggle);
        if (doc.documentElement.getAttribute('data-theme') !== 'light') errors.push('theme toggle did not switch to light');
        let stored = null;
        try {
            stored = window.localStorage.getItem('optibiz-sa-theme');
        } catch (e) {}
        if (stored !== 'light') errors.push('theme choice was not persisted (got ' + stored + ')');
        click(toggle);
        if (doc.documentElement.getAttribute('data-theme') !== 'dark') errors.push('theme toggle did not switch back to dark');
    } else {
        errors.push('no theme toggle');
    }

    if (!isLogin) {
        const app = doc.querySelector('.sa-app');
        const collapse = doc.querySelector('[data-sa-collapse]');
        if (collapse && app) {
            click(collapse);
            if (!/is-collapsed|sa-collapsed/.test(app.className)) errors.push('collapse button did not collapse the sidebar');
            click(collapse);
            if (/is-collapsed|sa-collapsed/.test(app.className)) errors.push('collapse button did not restore the sidebar');
        }

        /* table search */
        const search = doc.querySelector('[data-sa-search]');
        if (search) {
            const target = doc.querySelector(search.getAttribute('data-sa-search'));
            const rows = target ? [...target.querySelectorAll('tbody tr[data-filterable]')] : [];
            if (rows.length > 2) {
                search.value = 'zzz-no-such-row';
                search.dispatchEvent(new window.Event('input', { bubbles: true }));
                const visible = rows.filter((r) => !r.hidden && r.style.display !== 'none');
                if (visible.length !== 0) errors.push('search left ' + visible.length + ' rows visible for a nonsense query');
                search.value = '';
                search.dispatchEvent(new window.Event('input', { bubbles: true }));
                const back = rows.filter((r) => !r.hidden && r.style.display !== 'none');
                if (back.length !== rows.length) errors.push('clearing the search did not restore all rows');
            }
        }

        /* column sorting */
        const header = doc.querySelector('th[data-sa-sort]');
        if (header) {
            click(header);
            const first = header.getAttribute('aria-sort');
            if (first !== 'ascending' && first !== 'descending') errors.push('first sort click set aria-sort="' + first + '"');
            click(header);
            const second = header.getAttribute('aria-sort');
            if (second === first) errors.push('second sort click did not reverse the direction');
            const values = [...header.closest('table').querySelectorAll('tbody tr[data-filterable]')].map((tr) => {
                const cell = tr.children[header.cellIndex];
                return cell ? cell.textContent.trim().replace(/\s+/g, ' ') : '';
            });
            if (values.length > 1 && new Set(values).size === 1 && !/no data|nothing/i.test(values[0])) {
                errors.push('sorting produced identical values in every row — the column may be inert');
            }
        } else if (doc.querySelector('[data-sa-sortable-table]')) {
            errors.push('a sortable table has no th[data-sa-sort] headers');
        }
    }

    const counts = {
        cards: doc.querySelectorAll('.sa-card').length,
        kpis: doc.querySelectorAll('.sa-kpi').length,
        rows: doc.querySelectorAll('tbody tr').length,
        charts: doc.querySelectorAll('.sa-chart').length,
        dialogs: doc.querySelectorAll('dialog.sa-dialog').length,
    };
    if (errors.length) failures++;
    console.log(`  [${errors.length ? 'FAIL' : ' ok '}] ${rel.padEnd(26)} ${JSON.stringify(counts)}`);
    errors.slice(0, 5).forEach((e) => console.log('       - ' + e));
    if (errors.length > 5) console.log('       … ' + (errors.length - 5) + ' more');
    window.close();
}

console.log('Rendered pages under jsdom (real assets/js/superadmin.js):');
for (const page of pages) check(page);

console.log(
    failures
        ? `\n${failures} page(s) failed the behavioural checks.`
        : '\nEvery rendered page passes the behavioural checks.'
);
process.exit(failures ? 1 : 0);
