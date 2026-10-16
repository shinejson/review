#!/usr/bin/env node
/**
 * Build the default Open Graph / social share image.
 *
 *   node tools/build-og-image.js
 *
 * Social networks (Facebook, LinkedIn, WhatsApp, Slack, X) do NOT render SVG
 * for og:image — it must be a raster PNG or JPG. The recommended size is
 * 1200x630. This script generates that image from the same brand primitives as
 * the favicon so the two always match, with zero third-party dependencies.
 *
 * Output: assets/images/og-default.png (1200x630)
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { render: renderIcon } = require('./build-favicon.js');
const { encodePng } = require('./build-favicon.js');

const OUT_DIR = path.join(__dirname, '..', 'assets', 'images');
const W = 1200;
const H = 630;

const NAVY = [0x0f, 0x24, 0x38];
const LIME = [0xc2, 0xf5, 0x42];
const MUTED = [0x94, 0xa3, 0xb8];

// ---------------------------------------------------------------------------
// Stroke font
// ---------------------------------------------------------------------------
// Each glyph is a list of polylines in a 100-unit em box: x grows right from
// the glyph origin, y grows DOWN with 0 = cap top and 100 = baseline.
// `w` is the advance width. Curves are approximated with few segments, which
// reads as a deliberate geometric face at wordmark sizes.

const GLYPHS = {
    A: { w: 62, p: [[[0, 100], [31, 0], [62, 100]], [[13, 62], [49, 62]]] },
    B: { w: 60, p: [[[0, 0], [0, 100]], [[0, 0], [36, 0], [52, 14], [52, 38], [36, 52], [0, 52]], [[0, 52], [40, 52], [56, 66], [56, 86], [40, 100], [0, 100]]] },
    C: { w: 60, p: [[[58, 20], [46, 6], [30, 0], [14, 4], [4, 18], [0, 40], [0, 60], [4, 82], [14, 96], [30, 100], [46, 94], [58, 80]]] },
    D: { w: 60, p: [[[0, 0], [0, 100]], [[0, 0], [34, 0], [54, 20], [54, 80], [34, 100], [0, 100]]] },
    E: { w: 56, p: [[[56, 0], [0, 0], [0, 100], [56, 100]], [[0, 50], [44, 50]]] },
    F: { w: 54, p: [[[54, 0], [0, 0], [0, 100]], [[0, 50], [42, 50]]] },
    G: { w: 62, p: [[[58, 20], [46, 6], [30, 0], [14, 4], [4, 18], [0, 40], [0, 60], [4, 82], [14, 96], [30, 100], [48, 98], [58, 86], [58, 60], [34, 60]]] },
    H: { w: 58, p: [[[0, 0], [0, 100]], [[56, 0], [56, 100]], [[0, 50], [56, 50]]] },
    I: { w: 30, p: [[[14, 0], [14, 100]]] },
    J: { w: 54, p: [[[50, 0], [50, 78], [40, 94], [26, 100], [12, 94], [4, 82], [2, 68]]] },
    K: { w: 60, p: [[[0, 0], [0, 100]], [[54, 0], [2, 60]], [[18, 44], [58, 100]]] },
    L: { w: 52, p: [[[0, 0], [0, 100], [50, 100]]] },
    M: { w: 76, p: [[[0, 100], [0, 0], [38, 56], [76, 0], [76, 100]]] },
    N: { w: 60, p: [[[0, 100], [0, 0], [56, 100], [56, 0]]] },
    O: { w: 62, ellipse: true },
    P: { w: 58, p: [[[0, 0], [0, 100]], [[0, 0], [38, 0], [54, 16], [54, 40], [38, 56], [0, 56]]] },
    Q: { w: 64, ellipse: true, extra: [[[38, 78], [60, 104]]] },
    R: { w: 60, p: [[[0, 0], [0, 100]], [[0, 0], [38, 0], [54, 16], [54, 38], [38, 54], [0, 54]], [[26, 54], [58, 100]]] },
    S: { w: 58, p: [[[56, 18], [44, 4], [28, 0], [12, 4], [2, 16], [0, 32], [6, 46], [20, 54], [40, 62], [52, 72], [54, 86], [44, 98], [28, 102], [12, 98], [2, 88]]] },
    T: { w: 58, p: [[[0, 0], [58, 0]], [[29, 0], [29, 100]]] },
    U: { w: 60, p: [[[0, 0], [0, 74], [10, 92], [30, 100], [50, 92], [60, 74], [60, 0]]] },
    V: { w: 60, p: [[[0, 0], [30, 100], [60, 0]]] },
    W: { w: 84, p: [[[0, 0], [16, 100], [42, 22], [68, 100], [84, 0]]] },
    X: { w: 58, p: [[[0, 0], [58, 100]], [[58, 0], [0, 100]]] },
    Y: { w: 58, p: [[[0, 0], [29, 50], [58, 0]], [[29, 50], [29, 100]]] },
    Z: { w: 58, p: [[[0, 0], [58, 0], [0, 100], [58, 100]]] },
    0: { w: 60, ellipse: true },
    1: { w: 40, p: [[[8, 20], [26, 0], [26, 100]]] },
    2: { w: 56, p: [[[2, 20], [14, 6], [30, 0], [44, 6], [52, 22], [40, 54], [0, 100], [56, 100]]] },
    3: { w: 56, p: [[[4, 12], [20, 2], [36, 6], [46, 22], [36, 40], [20, 44], [46, 52], [54, 72], [44, 92], [26, 100], [8, 94], [0, 82]]] },
    4: { w: 58, p: [[[40, 100], [40, 0], [0, 68], [56, 68]]] },
    5: { w: 54, p: [[[52, 0], [10, 0], [4, 44], [24, 50], [40, 60], [46, 78], [32, 96], [12, 100], [0, 90]]] },
    6: { w: 56, p: [[[50, 12], [34, 2], [18, 8], [8, 26], [4, 60], [10, 88], [26, 100], [42, 96], [50, 84], [44, 70], [26, 62], [8, 58]]] },
    7: { w: 56, p: [[[0, 0], [56, 0], [20, 100]]] },
    8: { w: 56, p: [[[28, 0], [12, 6], [4, 20], [6, 34], [20, 44], [40, 54], [52, 68], [50, 86], [36, 100], [20, 96], [10, 82], [12, 66], [26, 54], [46, 42], [54, 26], [48, 8], [28, 0]]] },
    9: { w: 56, p: [[[6, 88], [22, 98], [38, 94], [48, 78], [52, 44], [46, 16], [30, 4], [12, 8], [4, 24], [10, 38], [26, 42], [44, 52], [52, 68], [50, 88]]] },
    '&': { w: 62, p: [[[54, 100], [16, 46], [34, 26], [46, 36], [50, 52], [34, 66], [4, 100]]] },
    ',': { w: 26, p: [[[16, 86], [8, 102]]] },
    '.': { w: 26, p: [[[10, 92], [14, 100]]] },
    '-': { w: 44, p: [[[8, 54], [36, 54]]] },
    '/': { w: 48, p: [[[0, 100], [48, 0]]] },
    '!': { w: 26, p: [[[12, 0], [10, 66]], [[10, 86], [12, 100]]] },
    "'": { w: 24, p: [[[14, 2], [8, 20]]] },
    ':': { w: 26, p: [[[12, 30], [14, 38]], [[12, 76], [14, 84]]] },
    '·': { w: 30, p: [[[10, 62], [16, 68]]] },
};

/** Build the ellipse polyline used by O / Q / 0. */
function ellipsePolyline(w) {
    const cx = w / 2;
    const rx = w / 2;
    const ry = 50;
    const pts = [];
    const steps = 36;
    for (let i = 0; i <= steps; i++) {
        const t = (i / steps) * Math.PI * 2;
        pts.push([cx + rx * Math.cos(t), 50 + ry * Math.sin(t)]);
    }
    return [pts];
}

/**
 * Lay out a line of text as a list of capsules (stroked segments).
 * @returns {{capsules: Array, width: number}}
 */
function layoutText(text, opts) {
    const capHeight = opts.capHeight; // px
    const tracking = opts.tracking !== undefined ? opts.tracking : capHeight * 0.12;
    const stroke = opts.stroke !== undefined ? opts.stroke : capHeight * 0.135;
    const scale = capHeight / 100;
    const originX = opts.x;
    const baselineY = opts.y;

    const capsules = [];
    let penX = 0;

    for (const rawChar of String(text).toUpperCase()) {
        const char = rawChar === ' ' ? null : rawChar;
        if (char === null) {
            penX += capHeight * 0.42;
            continue;
        }
        const g = GLYPHS[char];
        if (!g) {
            penX += capHeight * 0.42;
            continue;
        }
        const polylines = (g.p || []).concat(g.ellipse ? ellipsePolyline(g.w) : [], g.extra || []);
        for (const poly of polylines) {
            for (let i = 0; i < poly.length - 1; i++) {
                const x1 = originX + (penX + poly[i][0]) * scale;
                const y1 = baselineY - 100 * scale + poly[i][1] * scale;
                const x2 = originX + (penX + poly[i + 1][0]) * scale;
                const y2 = baselineY - 100 * scale + poly[i + 1][1] * scale;
                capsules.push(makeCapsule(x1, y1, x2, y2, stroke / 2));
            }
            // A single-point polyline (the middle dot) still needs a mark.
            if (poly.length === 1) {
                const x = originX + (penX + poly[0][0]) * scale;
                const y = baselineY - 100 * scale + poly[0][1] * scale;
                capsules.push(makeCapsule(x, y, x, y, stroke / 2));
            }
        }
        penX += g.w + tracking / scale;
    }

    return { capsules, width: penX * scale };
}

/** Capsule (line segment with rounded ends) plus a bounding box for fast rejection. */
function makeCapsule(x1, y1, x2, y2, r) {
    return {
        x1, y1, x2, y2, r,
        minx: Math.min(x1, x2) - r,
        maxx: Math.max(x1, x2) + r,
        miny: Math.min(y1, y2) - r,
        maxy: Math.max(y1, y2) + r,
    };
}

/** Signed distance from a point to a capsule. */
function sdCapsule(px, py, c) {
    const vx = c.x2 - c.x1;
    const vy = c.y2 - c.y1;
    const wx = px - c.x1;
    const wy = py - c.y1;
    const len2 = vx * vx + vy * vy;
    let t = len2 > 0 ? (wx * vx + wy * vy) / len2 : 0;
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    const dx = wx - vx * t;
    const dy = wy - vy * t;
    return Math.hypot(dx, dy) - c.r;
}

// ---------------------------------------------------------------------------
// Compositing
// ---------------------------------------------------------------------------

/** Create an RGBA buffer filled with a solid colour. */
function makeCanvas(w, h, rgb) {
    const buf = Buffer.alloc(w * h * 4);
    for (let i = 0; i < buf.length; i += 4) {
        buf[i] = rgb[0];
        buf[i + 1] = rgb[1];
        buf[i + 2] = rgb[2];
        buf[i + 3] = 255;
    }
    return buf;
}

function blendPixel(buf, w, idx, rgb, alpha) {
    if (alpha <= 0) return;
    const a = Math.min(1, alpha);
    buf[idx] = Math.round(buf[idx] * (1 - a) + rgb[0] * a);
    buf[idx + 1] = Math.round(buf[idx + 1] * (1 - a) + rgb[1] * a);
    buf[idx + 2] = Math.round(buf[idx + 2] * (1 - a) + rgb[2] * a);
}

/** Draw capsules into the canvas with 3x3 supersampled edge antialiasing. */
function drawCapsules(buf, w, h, capsules, rgb, ss = 3) {
    if (!capsules.length) return;
    // Only touch the bounding box of the whole run.
    let minx = Infinity, miny = Infinity, maxx = -Infinity, maxy = -Infinity;
    for (const c of capsules) {
        if (c.minx < minx) minx = c.minx;
        if (c.miny < miny) miny = c.miny;
        if (c.maxx > maxx) maxx = c.maxx;
        if (c.maxy > maxy) maxy = c.maxy;
    }
    const x0 = Math.max(0, Math.floor(minx));
    const x1 = Math.min(w - 1, Math.ceil(maxx));
    const y0 = Math.max(0, Math.floor(miny));
    const y1 = Math.min(h - 1, Math.ceil(maxy));
    const aa = 1.0;
    const inv = 1 / (ss * ss);

    for (let y = y0; y <= y1; y++) {
        for (let x = x0; x <= x1; x++) {
            let acc = 0;
            for (let sy = 0; sy < ss; sy++) {
                for (let sx = 0; sx < ss; sx++) {
                    const px = x + (sx + 0.5) / ss;
                    const py = y + (sy + 0.5) / ss;
                    let best = Infinity;
                    for (let ci = 0; ci < capsules.length; ci++) {
                        const c = capsules[ci];
                        if (px < c.minx || px > c.maxx || py < c.miny || py > c.maxy) continue;
                        const d = sdCapsule(px, py, c);
                        if (d < best) best = d;
                    }
                    if (best < Infinity) {
                        acc += Math.min(Math.max(0.5 - best / aa, 0), 1);
                    }
                }
            }
            const cov = acc * inv;
            if (cov > 0) {
                blendPixel(buf, w, (y * w + x) * 4, rgb, cov);
            }
        }
    }
}

/** Bilinear blit of a square RGBA source into the canvas. */
function blit(buf, w, h, src, srcSize, dx, dy, dw, dh) {
    const x0 = Math.max(0, Math.floor(dx));
    const x1 = Math.min(w - 1, Math.ceil(dx + dw));
    const y0 = Math.max(0, Math.floor(dy));
    const y1 = Math.min(h - 1, Math.ceil(dy + dh));

    for (let y = y0; y <= y1; y++) {
        for (let x = x0; x <= x1; x++) {
            const u = ((x + 0.5 - dx) / dw) * srcSize - 0.5;
            const v = ((y + 0.5 - dy) / dh) * srcSize - 0.5;
            if (u < -0.5 || v < -0.5 || u > srcSize - 0.5 || v > srcSize - 0.5) continue;
            const sx0 = Math.floor(u), sy0 = Math.floor(v);
            const sx1 = Math.min(srcSize - 1, sx0 + 1), sy1 = Math.min(srcSize - 1, sy0 + 1);
            const fx = u - sx0, fy = v - sy0;
            const a = Math.min(1, Math.max(0, fx)), b = Math.min(1, Math.max(0, fy));
            let r = 0, g = 0, bl = 0, al = 0;
            const corners = [[sx0, sy0, (1 - a) * (1 - b)], [sx1, sy0, a * (1 - b)],
                             [sx0, sy1, (1 - a) * b], [sx1, sy1, a * b]];
            for (const [cx, cy, wt] of corners) {
                const i = (cy * srcSize + cx) * 4;
                const sa = src[i + 3] / 255;
                r += src[i] * sa * wt;
                g += src[i + 1] * sa * wt;
                bl += src[i + 2] * sa * wt;
                al += sa * wt;
            }
            if (al > 0) {
                blendPixel(buf, w, (y * w + x) * 4, [r / al, g / al, bl / al], al);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
function main() {
    const canvas = makeCanvas(W, H, NAVY);

    // --- Brand mark ---------------------------------------------------------
    const iconSize = 256;
    const icon = renderIcon(iconSize, 3);
    const iconBox = 168;
    blit(canvas, W, H, icon, iconSize, 104, (H - iconBox) / 2, iconBox, iconBox);

    // --- Wordmark -----------------------------------------------------------
    const word = layoutText('OPTIBIZ', {
        x: 320, y: 330, capHeight: 108, tracking: 16, stroke: 15,
    });
    drawCapsules(canvas, W, H, word.capsules, LIME, 3);

    // --- Tagline ------------------------------------------------------------
    const tag = layoutText('REVIEWS · TRUST · REPUTATION GROWTH', {
        x: 324, y: 402, capHeight: 26, tracking: 5, stroke: 4,
    });
    drawCapsules(canvas, W, H, tag.capsules, MUTED, 3);

    // --- Accent rule --------------------------------------------------------
    const rule = [makeCapsule(324, 268, 324 + 92, 268, 3)];
    drawCapsules(canvas, W, H, rule, LIME, 3);

    fs.mkdirSync(OUT_DIR, { recursive: true });
    const png = encodePng(canvas, W, H);
    const out = path.join(OUT_DIR, 'og-default.png');
    fs.writeFileSync(out, png);
    console.log(`  og-default.png  ${W}x${H}  ${(png.length / 1024).toFixed(1)} KB`);
    console.log('\nOpen Graph image written to assets/images/og-default.png');
}

if (require.main === module) {
    main();
}
