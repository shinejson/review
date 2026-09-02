#!/usr/bin/env node
/**
 * ============================================================
 *  Schema check for every SQL statement the app issues
 * ============================================================
 *      node tools/check-sql.js
 *
 *  The mock database in tools/php/mock-db.php answers queries by
 *  pattern, so it cannot tell us whether a column really exists.
 *  This script closes that gap:
 *
 *    1. runs the PHP render harness with SA_SQL_DUMP set, capturing
 *       every statement the pages send (GET renders and all POST
 *       handlers);
 *    2. parses the CREATE TABLE blocks in database.sql;
 *    3. resolves each statement's tables and aliases, then verifies
 *       that every qualified column (`t.col`) exists in that table,
 *       and — for single-table statements — that every column named
 *       after WHERE exists too.
 *
 *  A typo in a column name is invisible to the mock and fatal against
 *  a real MySQL server, so this is the check that matters most before
 *  pointing the app at a live database.
 * ============================================================ */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const SCHEMA = path.join(ROOT, 'database.sql');
const DUMP = process.env.SA_SQL_DUMP_FILE || path.join(os.tmpdir(), 'sa-sql-dump-' + process.pid + '.txt');

const KEYWORDS = new Set(
    ('select from where and or not in is null as on using join left right inner outer cross group by order asc desc ' +
     'limit offset insert into values update set delete distinct case when then else end having union all exists ' +
     'between like interval day days month months year years true false for lock').split(/\s+/)
);
const FUNCTIONS = new Set(
    ('count sum avg min max coalesce ifnull if date date_add date_sub date_format curdate now timestampdiff ' +
     'timestampadd field concat concat_ws round floor ceil abs length lower upper trim substring substr replace ' +
     'cast convert group_concat json_extract date_add str_to_date unix_timestamp from_unixtime rand greatest ' +
     'least nullif database schema').split(/\s+/)
);

/* ---------- 1. capture ---------- */
if (!process.env.SA_SQL_DUMP_FILE) {
    fs.writeFileSync(DUMP, '');
    const r = spawnSync(process.execPath, [path.join(__dirname, 'render-php-preview.js')], {
        cwd: ROOT,
        env: Object.assign({}, process.env, { SA_SQL_DUMP: DUMP }),
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    if (r.status !== 0) {
        console.error('The render harness failed, so there is nothing to check.');
        process.exit(r.status || 1);
    }
}

const statements = [
    ...new Set(
        fs
            .readFileSync(DUMP, 'utf8')
            .split('\n')
            .filter((l) => l.startsWith('sql\t'))
            .map((l) => l.slice(4).trim())
    ),
].sort();

/* ---------- 2. schema ---------- */
const schemaText = fs.readFileSync(SCHEMA, 'utf8');
const schema = new Map();
for (const m of schemaText.matchAll(/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z_]+)`?\s*\(([\s\S]*?)\n\)\s*(?:ENGINE|;)/gi)) {
    const table = m[1].toLowerCase();
    const cols = new Set();
    for (const line of m[2].split('\n')) {
        const cm = line.match(/^\s*`?([a-z0-9_]+)`?\s+[A-Za-z]/i);
        if (cm && !/^(primary|unique|key|index|constraint|foreign|fulltext)\b/i.test(cm[1])) cols.add(cm[1].toLowerCase());
    }
    if (cols.size) schema.set(table, cols);
}

if (!schema.size) {
    console.error('Could not parse any CREATE TABLE blocks from database.sql');
    process.exit(2);
}

/* ---------- 3. check ---------- */
function referencedTables(sql) {
    const map = new Map();
    const re = /\b(?:FROM|JOIN|UPDATE|INTO)\s+`?([a-z_]+)`?(?:\s+(?:AS\s+)?`?([a-z][a-z0-9_]*))?/gi;
    for (const m of sql.matchAll(re)) {
        const table = m[1].toLowerCase();
        if (!schema.has(table)) continue;
        const alias = m[2] ? m[2].toLowerCase() : null;
        if (alias && !KEYWORDS.has(alias) && !FUNCTIONS.has(alias)) map.set(alias, table);
        map.set(table, table);
    }
    return map;
}

const stripLiterals = (sql) => sql.replace(/'[^']*'/g, "''");
const selectAliases = (sql) => new Set([...sql.matchAll(/\bAS\s+`?([a-z0-9_]+)`?/gi)].map((m) => m[1].toLowerCase()));

let failures = 0;
const problems = [];
let checkedRefs = 0;

for (const raw of statements) {
    const sql = stripLiterals(raw);
    const tables = referencedTables(sql);
    const aliases = selectAliases(sql);
    const realTables = [...new Set(tables.values())];

    // qualified references: alias.col
    for (const m of sql.matchAll(/\b([a-z][a-z0-9_]*)\.([a-z0-9_*]+)/gi)) {
        const prefix = m[1].toLowerCase();
        const col = m[2].toLowerCase();
        if (!tables.has(prefix) || col === '*') continue;
        checkedRefs++;
        const table = tables.get(prefix);
        const cols = schema.get(table);
        if (cols && !cols.has(col)) problems.push(`\`${prefix}.${col}\` — \`${table}\` has no column "${col}"\n         ${raw.slice(0, 160)}`);
    }

    // bare references after WHERE, only when a single table is in play
    if (realTables.length === 1) {
        const at = sql.search(/\b(?:WHERE|SET|ON|GROUP BY|ORDER BY|HAVING)\b/i);
        if (at !== -1) {
            const tail = sql.slice(at);
            for (const m of tail.matchAll(/\b([a-z_][a-z0-9_]*)\b(?!\s*\()/gi)) {
                const word = m[1].toLowerCase();
                if (KEYWORDS.has(word) || FUNCTIONS.has(word) || aliases.has(word) || tables.has(word)) continue;
                if (/^(asc|desc|y|m|d|ym|cnt|c|s|n)$/.test(word)) continue;
                checkedRefs++;
                const cols = schema.get(realTables[0]);
                if (cols && !cols.has(word)) problems.push(`"${word}" — \`${realTables[0]}\` has no such column\n         ${raw.slice(0, 160)}`);
            }
        }
    }
}

console.log(`database.sql: ${schema.size} tables. Captured ${statements.length} distinct statements, ${checkedRefs} column references checked.`);
for (const [table, cols] of schema) console.log(`  ${table.padEnd(20)} ${String(cols.size).padStart(2)} columns`);

const unique = [...new Set(problems)];
if (unique.length) {
    failures++;
    console.log('\nCOLUMN REFERENCES THAT DO NOT MATCH THE SCHEMA:');
    unique.slice(0, 30).forEach((p) => console.log('  ' + p));
    if (unique.length > 30) console.log('  … ' + (unique.length - 30) + ' more');
}

if (!process.env.SA_SQL_DUMP_FILE) fs.unlinkSync(DUMP);

console.log(
    failures
        ? `\n${unique.length} column reference(s) do not exist in database.sql.`
        : '\nEvery column the app references exists in database.sql.'
);
process.exit(failures ? 1 : 0);
