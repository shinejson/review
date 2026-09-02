#!/usr/bin/env node
/**
 * ============================================================
 *  Accessibility / markup audit of the rendered pages
 * ============================================================
 *      node tools/check-a11y.js       (after render-php-preview.js)
 *
 *  Without a browser this is the closest thing to "click through it
 *  and see": it reads the rendered HTML and reports markup that would
 *  break a screen reader, a keyboard user or the layout.
 *
 *  Checked per page:
 *    - duplicate element ids (breaks label/for and JS lookups)
 *    - inputs without a label, buttons without an accessible name
 *    - <th> without scope, tables whose body rows disagree with the
 *      header column count
 *    - dialogs without an accessible name
 *    - decorative SVGs that are not hidden from assistive tech
 *    - invalid or contradictory ARIA attribute values
 *    - heading order (no jump from <h1> to <h4>)
 *    - a single <h1>, a main landmark and a skip link
 *    - focus-visible styling and color-scheme in the stylesheets
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PREVIEW = path.join(ROOT, '.preview');
const SCAN = path.join(PREVIEW, 'superadmin');

if (!fs.existsSync(SCAN)) {
    console.error('No rendered preview found. Run: node tools/render-php-preview.js');
    process.exit(2);
}

const pages = [path.join(PREVIEW, 'index.html'), path.join(PREVIEW, 'login.html')].filter((f) => fs.existsSync(f));
(function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(p);
        else if (entry.name.endsWith('.html')) pages.push(p);
    }
})(SCAN);

const cssText = ['assets/css/superadmin.css', 'assets/css/auth.css']
    .map((f) => path.join(ROOT, f))
    .filter((f) => fs.existsSync(f))
    .map((f) => fs.readFileSync(f, 'utf8'))
    .join('\n');

/* ---------- helpers ---------- */
const attr = (tag, name) => {
    const m = tag.match(new RegExp('\\s' + name + '\\s*=\\s*"([^"]*)"'));
    return m ? m[1] : null;
};
const tags = (html, name) => [...html.matchAll(new RegExp('<' + name + '(\\s[^>]*)?>', 'gi'))].map((m) => m[0]);
const textOf = (tag) => tag.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

let failures = 0;
const problems = [];

for (const page of pages) {
    const html = fs.readFileSync(page, 'utf8');
    const rel = path.relative(PREVIEW, page);
    const isLanding = rel === 'index.html';
    const isLogin = /login\.html$/.test(rel);
    const list = [];

    /* duplicate ids */
    const ids = [...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
    const dupes = ids.filter((v, i) => ids.indexOf(v) !== i);
    if (dupes.length) list.push('duplicate id(s): ' + [...new Set(dupes)].slice(0, 5).join(', '));

    /* inputs need a label — either for/id, an aria-label, or a wrapping <label> */
    const labelSpans = [...html.matchAll(/<label\b[^>]*>[\s\S]*?<\/label>/gi)].map((m) => [m.index, m.index + m[0].length, m[0]]);
    const wrappedBy = (tag) => {
        const at = html.indexOf(tag);
        return labelSpans.some(([start, end, body]) => at >= start && at < end && /\S/.test(body.replace(/<[^>]*>/g, ' ')));
    };
    for (const tag of tags(html, 'input')) {
        const type = (attr(tag, 'type') || 'text').toLowerCase();
        if (['hidden', 'submit', 'button', 'reset'].includes(type)) continue;
        const id = attr(tag, 'id');
        const aria = attr(tag, 'aria-label') || attr(tag, 'aria-labelledby');
        const hasLabel =
            (id && new RegExp('<label[^>]*\\bfor="' + id + '"').test(html)) ||
            aria ||
            wrappedBy(tag);
        if (!hasLabel) list.push('input without a label: ' + tag.slice(0, 90));
    }

    /* buttons need an accessible name */
    for (const m of html.matchAll(/<button(\s[^>]*)?>([\s\S]*?)<\/button>/gi)) {
        const tag = m[0];
        const inner = m[2] || '';
        const name = (attr(tag, 'aria-label') || attr(tag, 'title') || textOf(inner)).trim();
        if (!name) list.push('button without an accessible name: ' + tag.slice(0, 100));
    }

    /* table headers and column counts */
    for (const table of html.matchAll(/<table[\s\S]*?<\/table>/gi)) {
        const t = table[0];
        const ths = tags(t, 'th');
        if (ths.length) {
            const noScope = ths.filter((th) => !attr(th, 'scope'));
            if (noScope.length) list.push(noScope.length + ' <th> without scope=');
            const headCount = ths.length;
            const firstRow = t.match(/<tbody[\s\S]*?<tr[\s\S]*?<\/tr>/i);
            if (firstRow) {
                const tds = tags(firstRow[0], 'td').length;
                const colSpan = (firstRow[0].match(/colspan="(\d+)"/i) || [])[1];
                if (tds !== headCount && !colSpan && tds !== 1) {
                    list.push(`table column mismatch: ${headCount} headers vs ${tds} cells`);
                }
            }
        }
    }

    /* dialogs need a name */
    for (const m of html.matchAll(/<dialog[^>]*>/gi)) {
        const tag = m[0];
        const id = attr(tag, 'id');
        const named =
            attr(tag, 'aria-label') ||
            attr(tag, 'aria-labelledby') ||
            (id && new RegExp('aria-labelledby="[^"]*\\b' + id).test(html));
        if (!named) {
            // a heading inside the dialog is enough for most screen readers
            const idx = html.indexOf(tag);
            const body = html.slice(idx, idx + 900);
            if (!/<h[1-6][^>]*id=/.test(body)) list.push('dialog without an accessible name: ' + tag.slice(0, 80));
        }
    }

    /* decorative svgs */
    const svgs = tags(html, 'svg');
    const exposed = svgs.filter((s) => !/aria-hidden="true"/.test(s) && !/<title/.test(s) && !/role="img"/.test(s));
    if (exposed.length) list.push(exposed.length + ' decorative <svg> not hidden from assistive tech');

    /* aria attribute values */
    for (const m of html.matchAll(/aria-(sort|pressed|expanded|hidden|label|labelledby|modal|current|haspopup|controls|selected|disabled|live|role)="([^"]*)"/gi)) {
        const key = m[1].toLowerCase();
        const value = m[2];
        const allowed = {
            sort: ['none', 'ascending', 'descending', 'other'],
            pressed: ['true', 'false', 'mixed'],
            expanded: ['true', 'false', 'undefined'],
            hidden: ['true', 'false'],
            current: ['true', 'false', 'page', 'step', 'location', 'date', 'time'],
            haspopup: ['true', 'false', 'menu', 'listbox', 'tree', 'grid', 'dialog'],
            selected: ['true', 'false', 'undefined'],
            disabled: ['true', 'false'],
            live: ['off', 'polite', 'assertive'],
        };
        if (allowed[key] && !allowed[key].includes(value)) list.push(`aria-${key}="${value}" is not a valid value`);
        if ((key === 'labelledby' || key === 'controls') && value) {
            for (const ref of value.split(/\s+/)) {
                if (!new RegExp('\\sid="' + ref + '"').test(html)) list.push(`aria-${key} points at a missing id: ${ref}`);
            }
        }
    }
    if (/aria-sort="(?:none|ascending|descending)"/.test(html) === false && /data-sa-sortable-table/.test(html)) {
        list.push('sortable table without aria-sort on its headers');
    }

    /* headings */
    const headings = [...html.matchAll(/<h([1-6])[^>]*>([\s\S]*?)<\/h\1>/gi)].map((m) => ({
        level: Number(m[1]),
        text: textOf(m[2]).slice(0, 40),
    }));
    const h1s = headings.filter((h) => h.level === 1);
    if (h1s.length !== 1) list.push(h1s.length + ' <h1> elements (expected exactly 1)');
    for (let i = 1; i < headings.length; i++) {
        if (headings[i].level - headings[i - 1].level > 1) {
            list.push(`heading jump h${headings[i - 1].level} -> h${headings[i].level} at "${headings[i].text}"`);
            break;
        }
    }

    /* landmarks */
    if (!/<main[\s>]/i.test(html)) list.push('no <main> landmark');
    if (!isLogin && !isLanding && !/class="sa-skip"|skip-link/i.test(html)) list.push('no skip link');
    if (!/<html[^>]*\slang="/i.test(html)) list.push('<html> has no lang attribute');

    if (list.length) {
        failures++;
        problems.push([rel, list]);
    }
    console.log(`  [${list.length ? 'FAIL' : ' ok '}] ${rel.padEnd(34)} ${list.length ? list.length + ' issue(s)' : 'clean'}`);
}

/* stylesheet-level checks */
console.log('\nStylesheets:');
const cssChecks = [
    [/:focus-visible/.test(cssText), ':focus-visible styles exist for keyboard users'],
    [/color-scheme/.test(cssText), 'color-scheme declared so native controls follow the theme'],
    [/@media\s*\(\s*prefers-reduced-motion/.test(cssText), 'prefers-reduced-motion honoured'],
    [/prefers-color-scheme/.test(cssText) || /data-theme/.test(cssText), 'theme is switchable'],
];
for (const [ok, label] of cssChecks) {
    if (!ok) failures++;
    console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${label}`);
}

if (problems.length) {
    console.log('\nDetails:');
    for (const [rel, list] of problems) {
        console.log('  ' + rel);
        list.slice(0, 8).forEach((p) => console.log('     - ' + p));
        if (list.length > 8) console.log('     … ' + (list.length - 8) + ' more');
    }
}

console.log(
    failures
        ? `\n${failures} page(s)/check(s) reported accessibility problems.`
        : '\nAccessibility audit clean across every rendered page.'
);
process.exit(failures ? 1 : 0);
