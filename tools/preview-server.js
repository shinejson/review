#!/usr/bin/env node
/**
 * Tiny static server for the .preview/ build.
 *
 *   node tools/preview-server.js [port]
 *
 * Serves .preview/ at the site root and rewrites the ".php" URLs that the
 * generated markup contains (index.php -> index.html) so every link, the
 * sidebar and the breadcrumbs behave like the real PHP app.
 */

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(path.resolve(__dirname, '..'), '.preview');
const PORT = Number(process.argv[2] || process.env.PORT || 4173);
const HOST = '0.0.0.0';

const TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.webp': 'image/webp',
    '.ico': 'image/x-icon',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.txt': 'text/plain; charset=utf-8',
};

function resolve(urlPath) {
    let p = decodeURIComponent(urlPath.split('?')[0].split('#')[0]);
    if (p.endsWith('/')) p += 'index.html';
    if (p.endsWith('.php')) p = p.slice(0, -4) + '.html';

    // logout.php has no preview page — land on the login screen instead
    if (/(^|\/)logout\.html$/.test(p)) return path.join(ROOT, 'login.html');

    let file = path.join(ROOT, path.normalize(p).replace(/^(\.\.[/\\])+/, ''));
    if (!file.startsWith(ROOT)) return path.join(ROOT, 'index.html');
    if (fs.existsSync(file) && fs.statSync(file).isDirectory()) {
        file = path.join(file, 'index.html');
    }
    if (!fs.existsSync(file)) {
        // Fall back to the nearest index so stray links do not 404
        const candidate = p.startsWith('/superadmin')
            ? path.join(ROOT, 'superadmin', 'index.html')
            : path.join(ROOT, 'index.html');
        return fs.existsSync(candidate) ? candidate : null;
    }
    return file;
}

const server = http.createServer((req, res) => {
    const file = resolve(req.url);
    if (!file) {
        res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('Preview not built yet. Run: node tools/build-preview.js\n');
        return;
    }
    const ext = path.extname(file).toLowerCase();
    res.writeHead(200, {
        'Content-Type': TYPES[ext] || 'application/octet-stream',
        'Cache-Control': 'no-cache',
        'X-Frame-Options': 'SAMEORIGIN',
    });
    fs.createReadStream(file).pipe(res);
});

server.listen(PORT, HOST, () => {
    console.log(`Optibiz super admin preview -> http://${HOST}:${PORT}/`);
    console.log(`Dashboard                    -> http://${HOST}:${PORT}/superadmin/index.php`);
});
