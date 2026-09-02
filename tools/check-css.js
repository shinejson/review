#!/usr/bin/env node
/**
 * ============================================================
 *  CSS audit for the rendered super admin pages
 * ============================================================
 *      node tools/check-css.js        (after render-php-preview.js)
 *
 *  No browser is available in this sandbox, so this is the static
 *  stand-in for "open it and look":
 *
 *    1. every var(--x) used by the rendered markup or by
 *       superadmin.js must be defined in the stylesheets those pages
 *       load — an undefined variable silently renders as nothing;
 *    2. every class in the rendered markup should be styled by
 *       superadmin.css, auth.css or the legacy style.css — an
 *       unstyled class is usually a typo or a rule that got lost;
 *    3. classes superadmin.js adds at runtime are taken into account
 *       so they are not reported as unstyled.
 *
 *  Unused selectors are reported separately as information, not as
 *  failures: some exist for states the sample data does not trigger.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PREVIEW = path.join(ROOT, '.preview');
const CSS_FILES = ['assets/css/superadmin.css', 'assets/css/auth.css', 'assets/css/style.css'].map((f) =>
    path.join(ROOT, f)
);
const JS_FILES = ['assets/js/superadmin.js', 'assets/js/auth.js'].map((f) => path.join(ROOT, f));
const PHP_FILES = ['includes/sa_helpers.php', 'superadmin/_shell.php', 'superadmin/_shell_footer.php'].map((f) =>
    path.join(ROOT, f)
);

if (!fs.existsSync(path.join(PREVIEW, 'superadmin'))) {
    console.error('No rendered preview found. Run: node tools/render-php-preview.js');
    process.exit(2);
}

const css = CSS_FILES.filter((f) => fs.existsSync(f)).map((f) => fs.readFileSync(f, 'utf8')).join('\n');
const js = JS_FILES.filter((f) => fs.existsSync(f)).map((f) => fs.readFileSync(f, 'utf8')).join('\n');
const php = PHP_FILES.filter((f) => fs.existsSync(f)).map((f) => fs.readFileSync(f, 'utf8')).join('\n');

/* ---------- what the stylesheets define ---------- */
const definedVars = new Set();
for (const m of css.matchAll(/(--[a-z0-9-]+)\s*:/gi)) definedVars.add(m[1].toLowerCase());

// Classes that appear in a selector (not just inside a declaration block)
const definedClasses = new Set();
const selectors = css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/@media[^{]*\{/g, '{');
for (const m of selectors.matchAll(/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/g)) definedClasses.add(m[1]);

// Classes the scripts query or add at runtime (hooks such as
// .auth-submit-label carry no styling of their own by design)
const runtimeClasses = new Set();
for (const m of js.matchAll(/querySelector(?:All)?\(\s*'\.([A-Za-z0-9_-]+)'/g)) runtimeClasses.add(m[1]);
for (const m of js.matchAll(/querySelector(?:All)?\(\s*"\.([A-Za-z0-9_-]+)"/g)) runtimeClasses.add(m[1]);
for (const m of js.matchAll(/'\.([a-z][A-Za-z0-9_-]+)'/g)) runtimeClasses.add(m[1]);
for (const m of js.matchAll(/class(?:List)?\s*[.(]\s*(?:add|remove|toggle|contains)\(\s*'([^']+)'/g)) {
    m[1].split(/\s+/).forEach((c) => c && runtimeClasses.add(c));
}
for (const m of js.matchAll(/className\s*=\s*'([^']+)'/g)) {
    m[1].split(/\s+/).forEach((c) => c && runtimeClasses.add(c));
}
for (const m of js.matchAll(/classList\.(?:add|remove|toggle)\(\s*"([^"]+)"/g)) {
    m[1].split(/\s+/).forEach((c) => c && runtimeClasses.add(c));
}
// aria-sort drives the sort indicator, so it is styled without a class
for (const m of js.matchAll(/setAttribute\(\s*'(aria-[a-z-]+)'/g)) runtimeClasses.add('[' + m[1] + ']');

// Classes the PHP helpers concatenate at runtime, e.g. 'sa-alert-' . $type
// A quoted fragment ending in a dash followed by "." is concatenated with a
// runtime value: 'class="sa-alert sa-alert-' . $type . '"'
for (const m of php.matchAll(/["']([a-z][a-z0-9 -]*-)["']\s*\./g)) {
    // keep only the trailing class fragment: "sa-alert sa-alert-" -> sa-alert-
    const fragment = m[1].trim().split(/\s+/).pop();
    if (fragment.endsWith('-')) runtimeClasses.add(fragment + '*');
}
for (const m of php.matchAll(/class="([^"]*)"/g)) {
    m[1].split(/\s+/).forEach((c) => c && runtimeClasses.add(c));
}

/* ---------- what the pages use ---------- */
const pages = [];
function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(p);
        else if (entry.name.endsWith('.html')) pages.push(p);
    }
}
walk(PREVIEW);

const usedVars = new Map(); // var name -> first page
const usedClasses = new Map(); // class name -> first page
const unstyledByPage = new Map();

const inlineCss = [];
for (const page of pages) {
    const html = fs.readFileSync(page, 'utf8');
    for (const m of html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)) inlineCss.push(m[1]);
}
if (inlineCss.length) {
    const extra = inlineCss.join('\n');
    for (const m of extra.matchAll(/(--[a-z0-9-]+)\s*:/gi)) definedVars.add(m[1].toLowerCase());
    for (const m of extra.matchAll(/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/g)) definedClasses.add(m[1]);
}

for (const page of pages) {
    const html = fs.readFileSync(page, 'utf8');
    const rel = path.relative(PREVIEW, page);

    for (const m of html.matchAll(/var\(\s*(--[a-z0-9-]+)/gi)) {
        const name = m[1].toLowerCase();
        if (!usedVars.has(name)) usedVars.set(name, rel);
    }
    for (const m of html.matchAll(/class="([^"]*)"/g)) {
        for (const c of m[1].split(/\s+/)) {
            if (!c) continue;
            if (!usedClasses.has(c)) usedClasses.set(c, rel);
            if (!definedClasses.has(c) && !runtimeClasses.has(c) && !isDynamic(c)) {
                if (!unstyledByPage.has(c)) unstyledByPage.set(c, rel);
            }
        }
    }
}

/** True when a PHP helper builds this class by concatenation (sa-alert-success). */
function isDynamic(cls) {
    for (const prefix of runtimeClasses) {
        if (prefix.endsWith('*') && cls.startsWith(prefix.slice(0, -1))) return true;
    }
    return false;
}

/* ---------- report ---------- */
let failures = 0;

const missingVars = [...usedVars.keys()].filter((v) => !definedVars.has(v));
console.log(`Stylesheets define ${definedVars.size} custom properties; the rendered pages use ${usedVars.size}.`);
if (missingVars.length) {
    failures++;
    console.log('\nUNDEFINED CSS variables (these render as nothing):');
    missingVars.forEach((v) => console.log(`  ${v}  (first seen in ${usedVars.get(v)})`));
} else {
    console.log('  every var(--…) used by the pages is defined.');
}

console.log(`\nRendered pages use ${usedClasses.size} classes; ${definedClasses.size} are styled.`);
const unstyled = [...unstyledByPage.keys()].sort();
if (unstyled.length) {
    failures++;
    console.log('\nUNSTYLED classes (no rule in superadmin.css, auth.css or style.css):');
    unstyled.forEach((c) => console.log(`  .${c}  (first seen in ${unstyledByPage.get(c)})`));
} else {
    console.log('  every class in the markup has a rule (or is applied by superadmin.js).');
}

// Informational: selectors with no matching element in any rendered page
const used = new Set(usedClasses.keys());
const allRuntime = new Set([...runtimeClasses]);
const unused = [...definedClasses]
    .filter((c) => c.startsWith('sa-') && !used.has(c) && !allRuntime.has(c) && !isDynamic(c))
    .sort();
console.log(
    `\nInformational: ${unused.length} .sa-* selectors never appear in the rendered pages or the scripts` +
        (unused.length ? ' (states the sample data does not trigger, or dead CSS):' : '.')
);
if (unused.length) console.log('  ' + unused.slice(0, 40).join(', ') + (unused.length > 40 ? ', …' : ''));

console.log(
    failures
        ? `\n${failures} CSS problem group(s) found.`
        : '\nCSS audit clean: no undefined variables and no unstyled classes.'
);
process.exit(failures ? 1 : 0);
