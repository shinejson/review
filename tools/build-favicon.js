#!/usr/bin/env node
/**
 * Build the Optibiz favicon set.
 *
 *   node tools/build-favicon.mjs
 *
 * Why this exists
 * ---------------
 * The favicon is a real SEO/branding asset (it is the icon in the SERP on
 * mobile, in browser tabs and in the "Add to home screen" install prompt).
 * Generating it from source keeps it reproducible and means the brand mark can
 * be re-coloured without hunting for a design tool. It has zero dependencies —
 * it rasterises signed-distance shapes and writes PNGs with Node's zlib, so it
 * runs anywhere Node runs (no ImageMagick, no sharp, no gd).
 *
 * Outputs (all under assets/images/favicon/):
 *   favicon.svg               — vector, served to modern browsers
 *   favicon-32.png            — classic 32x32 tab icon
 *   favicon-192.png           — Android / manifest
 *   favicon-512.png           — Android splash / manifest
 *   apple-touch-icon.png      — 180x180 iOS home screen
 *   favicon.ico               — 32x32 PNG wrapped in an ICO container (legacy)
 */

'use strict';

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

// ---------------------------------------------------------------------------
// Brand palette — keep in sync with assets/css/* and index.php :root
// ---------------------------------------------------------------------------
const LIME = [0xc2, 0xf5, 0x42]; // --accent-lime
const NAVY = [0x0f, 0x24, 0x38]; // --primary-dark

const OUT_DIR = path.join(__dirname, '..', 'assets', 'images', 'favicon');

// ---------------------------------------------------------------------------
// Tiny SDF toolkit (iq-style distance functions, all in "glyph space" 0..24)
// ---------------------------------------------------------------------------

/** Signed distance to an axis-aligned box with rounded corners. */
function sdRoundedBox(px, py, cx, cy, hx, hy, r) {
    const qx = Math.abs(px - cx) - hx + r;
    const qy = Math.abs(py - cy) - hy + r;
    const mx = Math.max(qx, 0);
    const my = Math.max(qy, 0);
    return Math.min(Math.max(qx, qy), 0) + Math.hypot(mx, my) - r;
}

/** Signed distance to a circle. */
function sdCircle(px, py, cx, cy, r) {
    return Math.hypot(px - cx, py - cy) - r;
}

/** Signed distance to an equilateral-ish triangle (iq's sdTriangle). */
function sdTriangle(px, py, x1, y1, x2, y2, x3, y3) {
    const e = (ax, ay, bx, by) => {
        const vx = bx - ax;
        const vy = by - ay;
        const wx = px - ax;
        const wy = py - ay;
        let t = (wx * vx + wy * vy) / (vx * vx + vy * vy);
        t = Math.min(Math.max(t, 0), 1);
        const dx = wx - vx * t;
        const dy = wy - vy * t;
        return { d: Math.hypot(dx, dy), s: Math.sign(vx * wy - vy * wx) };
    };
    const a = e(x1, y1, x2, y2);
    const b = e(x2, y2, x3, y3);
    const c = e(x3, y3, x1, y1);
    const inside = a.s > 0 && b.s > 0 && c.s > 0;
    const outside = Math.min(Math.min(a.d, b.d), c.d);
    return inside ? -outside : outside;
}

/**
 * The Optibiz mark: the same three "shapes" glyph used by the navbar logo
 * (fa-solid fa-shapes) — rounded square, circle, triangle.
 */
function sdGlyph(px, py) {
    return Math.min(
        // top-left rounded square
        sdRoundedBox(px, py, 5.6, 5.6, 4.4, 4.4, 1.15),
        // top-right circle
        sdCircle(px, py, 17.6, 5.6, 4.2),
        // bottom-centre triangle
        sdTriangle(px, py, 12.0, 12.6, 20.4, 20.4, 3.6, 20.4)
    );
}

/** Signed distance to the app-tile silhouette (rounded square). */
function sdTile(px, py, size, radius) {
    return sdRoundedBox(px, py, size / 2, size / 2, size / 2, size / 2, radius);
}

/** Linear interpolation. */
const lerp = (a, b, t) => a + (b - a) * t;

/**
 * Render one RGBA frame at `size` x `size`, supersampled `ss` x per axis.
 * Returns a Buffer of raw RGBA bytes (no PNG framing yet).
 */
function render(size, ss = 4) {
    const S = 24; // glyph design grid
    // The glyph is inset so it never touches the tile edge.
    const glyphInset = 0.145; // fraction of the tile kept clear on each side
    const glyphSpan = S * (1 - 2 * glyphInset);

    const buf = Buffer.alloc(size * size * 4);
    const inv = 1 / (ss * ss);

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            let r = 0;
            let g = 0;
            let b = 0;
            let a = 0;

            for (let sy = 0; sy < ss; sy++) {
                for (let sx = 0; sx < ss; sx++) {
                    // Sample at pixel centres, in 0..size space.
                    const px = (x + (sx + 0.5) / ss) * (S / size);
                    const py = (y + (sy + 0.5) / ss) * (S / size);

                    // Tile coverage (antialiased edge, ~1 design unit wide).
                    const dTile = sdTile(px, py, S, S * 0.235);
                    const aa = Math.max(1, S / size) * 0.75;
                    const tileCov = 1 - Math.min(Math.max(dTile / aa + 0.5, 0), 1);

                    // Glyph coverage, mapped into the inset glyph box.
                    const gx = (px - S * glyphInset) * (S / glyphSpan);
                    const gy = (py - S * glyphInset) * (S / glyphSpan);
                    const dGlyph = sdGlyph(gx, gy);
                    const glyphCov = 1 - Math.min(Math.max(dGlyph / aa + 0.5, 0), 1);

                    // Composite: lime tile, navy glyph on top.
                    const rr = lerp(LIME[0], NAVY[0], glyphCov);
                    const gg = lerp(LIME[1], NAVY[1], glyphCov);
                    const bb = lerp(LIME[2], NAVY[2], glyphCov);

                    r += rr * tileCov;
                    g += gg * tileCov;
                    b += bb * tileCov;
                    a += 255 * tileCov;
                }
            }

            const i = (y * size + x) * 4;
            // Straight (non-premultiplied) alpha — safe for browsers.
            const alpha = a * inv;
            if (alpha > 0) {
                buf[i] = Math.round(Math.min(255, (r * inv) / (alpha / 255)));
                buf[i + 1] = Math.round(Math.min(255, (g * inv) / (alpha / 255)));
                buf[i + 2] = Math.round(Math.min(255, (b * inv) / (alpha / 255)));
            }
            buf[i + 3] = Math.round(Math.min(255, alpha));
        }
    }
    return buf;
}

// ---------------------------------------------------------------------------
// Minimal PNG writer (RGBA, 8-bit, single IDAT)
// ---------------------------------------------------------------------------

const CRC_TABLE = (() => {
    const t = new Int32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        t[n] = c;
    }
    return t;
})();

function crc32(buf) {
    let c = -1;
    for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
    return (c ^ -1) >>> 0;
}

function chunk(type, data) {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length, 0);
    const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(body), 0);
    return Buffer.concat([len, body, crc]);
}

/** Encode RGBA pixels as a PNG. Defaults to a square image when height is omitted. */
function encodePng(rgba, width, height) {
    const w = width;
    const h = height === undefined ? width : height;

    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(w, 0);
    ihdr.writeUInt32BE(h, 4);
    ihdr[8] = 8; // bit depth
    ihdr[9] = 6; // colour type: truecolour + alpha
    ihdr[10] = 0; // deflate
    ihdr[11] = 0; // adaptive filtering
    ihdr[12] = 0; // no interlace

    // Raw scanlines, each prefixed with filter type 0 (None).
    const raw = Buffer.alloc(h * (w * 4 + 1));
    for (let y = 0; y < h; y++) {
        raw[y * (w * 4 + 1)] = 0;
        rgba.copy(raw, y * (w * 4 + 1) + 1, y * w * 4, (y + 1) * w * 4);
    }

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

/** Wrap a PNG in a single-image ICO container (PNG-in-ICO is valid since Vista). */
function encodeIco(png, size) {
    const header = Buffer.alloc(6);
    header.writeUInt16LE(0, 0); // reserved
    header.writeUInt16LE(1, 2); // type: icon
    header.writeUInt16LE(1, 4); // image count

    const entry = Buffer.alloc(16);
    entry[0] = size === 256 ? 0 : size; // width (0 means 256)
    entry[1] = size === 256 ? 0 : size; // height
    entry[2] = 0; // palette count
    entry[3] = 0; // reserved
    entry.writeUInt16LE(1, 4); // colour planes
    entry.writeUInt16LE(32, 6); // bits per pixel
    entry.writeUInt32BE(0, 8); // (bitness fields left zero for PNG entries)
    entry.writeUInt32LE(png.length, 8);
    entry.writeUInt32LE(header.length + entry.length, 12);

    return Buffer.concat([header, entry, png]);
}

// ---------------------------------------------------------------------------
// SVG (hand written, same geometry, so vector and raster match)
// ---------------------------------------------------------------------------
function svgSource() {
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img" aria-label="Optibiz">
  <title>Optibiz</title>
  <rect width="24" height="24" rx="5.64" fill="#c2f542"/>
  <rect x="1.2" y="1.2" width="8.8" height="8.8" rx="1.15" fill="#0f2438"/>
  <circle cx="17.6" cy="5.6" r="4.2" fill="#0f2438"/>
  <path d="M12 12.6 L20.4 20.4 L3.6 20.4 Z" fill="#0f2438"/>
</svg>
`;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
function main() {
    fs.mkdirSync(OUT_DIR, { recursive: true });

    fs.writeFileSync(path.join(OUT_DIR, 'favicon.svg'), svgSource());

    const targets = [
        ['favicon-32.png', 32],
        ['favicon-192.png', 192],
        ['favicon-512.png', 512],
        ['apple-touch-icon.png', 180],
    ];

    for (const [name, size] of targets) {
        const png = encodePng(render(size), size);
        fs.writeFileSync(path.join(OUT_DIR, name), png);
        console.log(`  ${name.padEnd(22)} ${size}x${size}  ${(png.length / 1024).toFixed(1)} KB`);
    }

    const ico = encodeIco(encodePng(render(32), 32), 32);
    fs.writeFileSync(path.join(OUT_DIR, 'favicon.ico'), ico);
    console.log(`  ${'favicon.ico'.padEnd(22)} 32x32    ${(ico.length / 1024).toFixed(1)} KB`);

    console.log('\nFavicons written to assets/images/favicon/');
}

// Only run when invoked directly, so the renderer can be required by tests.
if (require.main === module) {
    main();
} else {
    module.exports = { render, encodePng, encodeIco, svgSource, sdGlyph, sdTile };
}
