#!/usr/bin/env node
/**
 * ============================================================
 *  Optibiz Super Admin — static preview builder
 * ============================================================
 *  The real product is PHP + MySQL, which cannot run in this
 *  sandbox. This script renders the SAME markup and design
 *  system with sample data into `.preview/`, so the dashboard
 *  can be opened (and theme-toggled) in a browser.
 *
 *      node tools/build-preview.js
 *
 *  Output:
 *      .preview/assets/**            (copied from /assets)
 *      .preview/superadmin/*.html    (one per superadmin page)
 *      .preview/login.html
 *      .preview/index.html           (preview index)
 *
 *  `.preview/` is git-ignored — it is a build artefact.
 * ============================================================ */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, '.preview');
const CUR = '$';

/* ------------------------------------------------------------
   Sample data (mirrors what the PHP helpers would return)
   ------------------------------------------------------------ */
const months = ['Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep'];
const monthsLong = months.map((m, i) => `${m} ${2025 + Math.floor((i + 2) / 12)}`);

const trend = [
    { label: 'Oct', mrr: 210, tenants: 3, new: 1, ratings: 14, avg: 4.2 },
    { label: 'Nov', mrr: 240, tenants: 4, new: 1, ratings: 22, avg: 4.3 },
    { label: 'Dec', mrr: 320, tenants: 5, new: 1, ratings: 31, avg: 4.1 },
    { label: 'Jan', mrr: 400, tenants: 6, new: 2, ratings: 28, avg: 4.4 },
    { label: 'Feb', mrr: 460, tenants: 7, new: 1, ratings: 45, avg: 4.5 },
    { label: 'Mar', mrr: 620, tenants: 9, new: 2, ratings: 52, avg: 4.3 },
    { label: 'Apr', mrr: 700, tenants: 10, new: 1, ratings: 61, avg: 4.6 },
    { label: 'May', mrr: 860, tenants: 12, new: 2, ratings: 58, avg: 4.5 },
    { label: 'Jun', mrr: 940, tenants: 13, new: 1, ratings: 73, avg: 4.7 },
    { label: 'Jul', mrr: 1100, tenants: 15, new: 2, ratings: 81, avg: 4.6 },
    { label: 'Aug', mrr: 1260, tenants: 17, new: 2, ratings: 96, avg: 4.8 },
    { label: 'Sep', mrr: 1420, tenants: 18, new: 1, ratings: 104, avg: 4.7 },
];

const tenants = [
    { id: 18, company: 'Volta Logistics', email: 'billing@voltalogistics.com', username: 'volta_logistics', phone: '+233 24 555 0118', plan: 'Enterprise', plan_id: 3, price: 199.99, status: 'active', companies: 42, end: '2026-12-31', renew: 1, created: '2026-08-24 09:12:00', days: 120 },
    { id: 17, company: 'Harmattan Foods', email: 'accounts@harmattanfoods.gh', username: 'harmattan_foods', phone: '+233 20 555 0117', plan: 'Professional', plan_id: 2, price: 79.99, status: 'active', companies: 18, end: '2026-09-18', renew: 1, created: '2026-08-11 14:40:00', days: 16 },
    { id: 16, company: 'Kotoka Ground Services', email: 'ops@kotokaground.com', username: 'kotoka_ground_services', phone: '+233 30 555 0116', plan: 'Professional', plan_id: 2, price: 79.99, status: 'trial', companies: 6, end: '2026-09-09', renew: 0, created: '2026-08-09 11:02:00', days: 7 },
    { id: 15, company: 'Cocoa Coast Exports', email: 'finance@cocoacoast.gh', username: 'cocoa_coast_exports', phone: '+233 24 555 0115', plan: 'Enterprise', plan_id: 3, price: 199.99, status: 'active', companies: 31, end: '2027-02-28', renew: 1, created: '2026-07-28 08:55:00', days: 179 },
    { id: 14, company: 'Accra Dental Group', email: 'hello@accradental.com', username: 'accra_dental_group', phone: '+233 27 555 0114', plan: 'Starter', plan_id: 1, price: 29.99, status: 'active', companies: 4, end: '2026-09-04', renew: 0, created: '2026-07-19 16:20:00', days: 2 },
    { id: 13, company: 'Tamale Solar Ltd', email: 'info@tamalesolar.com', username: 'tamale_solar_ltd', phone: '+233 25 555 0113', plan: 'Professional', plan_id: 2, price: 79.99, status: 'active', companies: 12, end: '2026-11-30', renew: 1, created: '2026-07-02 10:05:00', days: 89 },
    { id: 12, company: 'Kumasi Textiles', email: 'sales@kumasitextiles.gh', username: 'kumasi_textiles', phone: '+233 32 555 0112', plan: 'Starter', plan_id: 1, price: 29.99, status: 'trial', companies: 3, end: '2026-09-22', renew: 0, created: '2026-06-22 13:48:00', days: 20 },
    { id: 11, company: 'Cape Coast Fintech', email: 'team@coastfintech.com', username: 'cape_coast_fintech', phone: '+233 24 555 0111', plan: 'Enterprise', plan_id: 3, price: 199.99, status: 'active', companies: 27, end: '2027-01-31', renew: 1, created: '2026-06-04 09:30:00', days: 151 },
    { id: 10, company: 'Ashanti AgriCo', email: 'admin@ashantiagri.gh', username: 'ashanti_agrico', phone: '+233 20 555 0110', plan: 'Professional', plan_id: 2, price: 79.99, status: 'active', companies: 15, end: '2026-10-15', renew: 1, created: '2026-05-18 15:12:00', days: 43 },
    { id: 9, company: 'Tema Steel Works', email: 'accounts@temasteel.com', username: 'tema_steel_works', plan: 'Professional', plan_id: 2, phone: '+233 30 555 0109', price: 79.99, status: 'inactive', companies: 9, end: '2026-08-01', renew: 0, created: '2026-04-30 12:00:00', days: -32 },
    { id: 8, company: 'Ho Mountain Tours', email: 'book@homountain.gh', username: 'ho_mountain_tours', phone: '+233 27 555 0108', plan: 'Starter', plan_id: 1, price: 29.99, status: 'active', companies: 5, end: '2026-12-01', renew: 1, created: '2026-04-12 09:44:00', days: 90 },
    { id: 7, company: 'Takoradi Marine', email: 'ops@takoradimarine.com', username: 'takoradi_marine', phone: '+233 24 555 0107', plan: 'Enterprise', plan_id: 3, price: 199.99, status: 'active', companies: 36, end: '2027-03-31', renew: 1, created: '2026-03-27 11:18:00', days: 210 },
    { id: 6, company: 'Sunyani Health Partners', email: 'care@sunyanihealth.gh', username: 'sunyani_health_partners', phone: '+233 25 555 0106', plan: 'Professional', plan_id: 2, price: 79.99, status: 'active', companies: 11, end: '2026-10-02', renew: 0, created: '2026-03-09 08:25:00', days: 30 },
    { id: 5, company: 'Obuasi Mining Supplies', email: 'sales@obuasisupplies.com', username: 'obuasi_mining_supplies', phone: '+233 32 555 0105', plan: 'Starter', plan_id: 1, price: 29.99, status: 'cancelled', companies: 2, end: '2026-06-30', renew: 0, created: '2026-02-21 17:02:00', days: -64 },
    { id: 4, company: 'Ada Beach Resorts', email: 'stay@adabeach.gh', username: 'ada_beach_resorts', phone: '+233 20 555 0104', plan: 'Professional', plan_id: 2, price: 79.99, status: 'active', companies: 8, end: '2026-11-11', renew: 1, created: '2026-02-02 10:35:00', days: 70 },
    { id: 3, company: 'Wa Shea Butter Co.', email: 'hello@washea.gh', username: 'wa_shea_butter_co', phone: '+233 27 555 0103', plan: 'Starter', plan_id: 1, price: 29.99, status: 'active', companies: 6, end: '2026-09-27', renew: 1, created: '2026-01-14 14:10:00', days: 25 },
    { id: 2, company: 'XYZ Industries', email: 'admin@xyzind.com', username: 'xyz_industries', phone: '555-0102', plan: 'Starter', plan_id: 1, price: 29.99, status: 'active', companies: 7, end: '2027-02-01', renew: 1, created: '2026-02-01 09:00:00', days: 152 },
    { id: 1, company: 'ABC Corporation', email: 'admin@abccorp.com', username: 'abc_corporation', phone: '555-0101', plan: 'Professional', plan_id: 2, price: 79.99, status: 'active', companies: 14, end: '2026-12-31', renew: 1, created: '2026-01-01 09:00:00', days: 120 },
];

const plans = [
    { id: 1, name: 'Starter', price: 29.99, ratings: 100, customers: 10, status: 'active', tenants: 6, trials: 2, mrr: 119.96, features: ['Basic analytics', 'Email support', 'Up to 10 companies', '100 ratings per month'] },
    { id: 2, name: 'Professional', price: 79.99, ratings: 500, customers: 50, status: 'active', tenants: 8, trials: 2, mrr: 559.93, features: ['Advanced analytics', 'Priority support', 'Up to 50 companies', '500 ratings per month', 'Custom branding'] },
    { id: 3, name: 'Enterprise', price: 199.99, ratings: 9999, customers: 999, status: 'active', tenants: 4, trials: 0, mrr: 799.96, features: ['Full analytics suite', '24/7 phone support', 'Unlimited companies', 'Unlimited ratings', 'API access', 'White label'] },
];

const quotes = [
    { id: 9, company: 'Bolgatanga Grain Traders', contact: 'Amina Fuseini', email: 'amina@bolgagrains.gh', phone: '+233 24 555 0209', website: 'bolgagrains.gh', location: 'Bolgatanga, Upper East', category: 'Retail', plan: 'Professional', plan_id: 2, companies: 12, ratings: 300, notes: 'We run 12 grain depots and want one place to collect customer feedback after every delivery.', status: 'pending', created: '2026-09-01 08:14:00' },
    { id: 8, company: 'Osu Nightlife Group', contact: 'Kwame Mensah', email: 'kwame@osugroup.com', phone: '+233 20 555 0208', website: 'osugroup.com', location: 'Accra, Greater Accra', category: 'Retail', plan: 'Starter', plan_id: 1, companies: 4, ratings: 80, notes: 'Three restaurants and a lounge. Mostly interested in the public rating page.', status: 'pending', created: '2026-08-30 19:42:00' },
    { id: 7, company: 'Northern Freight Co.', contact: 'Issahaku Bello', email: 'ops@northernfreight.gh', phone: '+233 25 555 0207', website: '', location: 'Tamale, Northern', category: 'Manufacturing', plan: 'Enterprise', plan_id: 3, companies: 26, ratings: 900, notes: 'Fleet of 26 trucks. Need API access to push our own NPS scores.', status: 'contacted', created: '2026-08-26 11:05:00' },
    { id: 6, company: 'Elmina Fisheries', contact: 'Grace Aidoo', email: 'grace@elminafish.gh', phone: '+233 27 555 0206', website: 'elminafish.gh', location: 'Elmina, Central', category: 'Manufacturing', plan: 'Starter', plan_id: 1, companies: 2, ratings: 40, notes: '', status: 'contacted', created: '2026-08-21 15:33:00' },
    { id: 5, company: 'Legon Biotech Labs', contact: 'Dr. Yaw Boateng', email: 'yaw@legonbiotech.com', phone: '+233 24 555 0205', website: 'legonbiotech.com', location: 'Legon, Greater Accra', category: 'Healthcare', plan: 'Enterprise', plan_id: 3, companies: 9, ratings: 250, notes: 'Interested in white labelling for our partner clinics.', status: 'converted', created: '2026-08-14 09:20:00' },
    { id: 4, company: 'Sekondi Shipyards', contact: 'Nana Osei', email: 'nana@sekondiship.gh', phone: '+233 30 555 0204', website: '', location: 'Sekondi-Takoradi, Western', category: 'Manufacturing', plan: 'Professional', plan_id: 2, companies: 6, ratings: 120, notes: 'Asked for a discount on annual billing.', status: 'rejected', created: '2026-08-02 13:47:00' },
];

const topCompanies = [
    { name: 'Takoradi Marine Terminal', ratings: 128, avg: 4.8 },
    { name: 'Cape Coast Fintech Hub', ratings: 96, avg: 4.7 },
    { name: 'Volta Logistics Fleet', ratings: 84, avg: 4.6 },
    { name: 'Cocoa Coast Exports', ratings: 71, avg: 4.9 },
    { name: 'Ashanti AgriCo Depot', ratings: 58, avg: 4.3 },
    { name: 'Tamale Solar Installers', ratings: 41, avg: 4.5 },
];

const activity = [
    { type: 'tenant', title: 'Volta Logistics', meta: 'New tenant · Active', at: '2 hours ago', when: '2026-09-02 07:40:00' },
    { type: 'rating', title: 'Cocoa Coast Exports', meta: '5-star rating from Abena O.', at: '3 hours ago', stars: 5 },
    { type: 'quote', title: 'Bolgatanga Grain Traders', meta: 'Quote request · Pending', at: '5 hours ago' },
    { type: 'rating', title: 'Takoradi Marine', meta: '4-star rating from Kojo A.', at: '9 hours ago', stars: 4 },
    { type: 'tenant', title: 'Harmattan Foods', meta: 'Upgraded Starter → Professional', at: 'yesterday' },
    { type: 'quote', title: 'Osu Nightlife Group', meta: 'Quote request · Pending', at: 'yesterday' },
    { type: 'rating', title: 'Accra Dental Group', meta: '3-star rating from Nii A.', at: '2 days ago', stars: 3 },
    { type: 'tenant', title: 'Tema Steel Works', meta: 'Subscription paused · Inactive', at: '3 days ago' },
];

const stars = { 5: 412, 4: 168, 3: 54, 2: 17, 1: 9 };
const totalRatings = Object.values(stars).reduce((a, b) => a + b, 0);
const avgRating =
    Object.entries(stars).reduce((sum, [k, v]) => sum + Number(k) * v, 0) / totalRatings;

const statusCounts = {
    active: tenants.filter((t) => t.status === 'active').length,
    trial: tenants.filter((t) => t.status === 'trial').length,
    inactive: tenants.filter((t) => t.status === 'inactive').length,
    cancelled: tenants.filter((t) => t.status === 'cancelled').length,
};
const mrr = tenants.filter((t) => t.status === 'active').reduce((s, t) => s + t.price, 0);

/* Deterministic pseudo-random ratings per day for the heatmap */
function ratingDays(count) {
    const out = [];
    let seed = 42;
    const rnd = () => {
        seed = (seed * 1103515245 + 12345) % 2147483648;
        return seed / 2147483648;
    };
    for (let i = count - 1; i >= 0; i--) {
        const d = new Date(Date.now() - i * 86400000);
        const weekend = d.getUTCDay() === 0 || d.getUTCDay() === 6;
        const base = weekend ? 1 : 4;
        const v = Math.max(0, Math.round(base + rnd() * 6 - 1.5));
        out.push({
            label: d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }),
            value: v,
        });
    }
    return out;
}
const heatCells = ratingDays(63);

/* ------------------------------------------------------------
   Formatting helpers (mirror includes/sa_helpers.php)
   ------------------------------------------------------------ */
const esc = (s) =>
    String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

const num = (n) => Number(n || 0).toLocaleString('en-US');
const money = (n, d = 2) => CUR + Number(n || 0).toFixed(d).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
const moneyShort = (n) => {
    n = Number(n || 0);
    if (Math.abs(n) >= 1000000) return CUR + (n / 1000000).toFixed(1) + 'M';
    if (Math.abs(n) >= 1000) return CUR + (n / 1000).toFixed(1) + 'k';
    return CUR + Math.round(n);
};
const pct = (part, total, d = 1) => (total > 0 ? ((part / total) * 100).toFixed(d) : '0');
const initials = (name) =>
    String(name)
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => (w.match(/[A-Za-z0-9]/) || ['?'])[0])
        .join('')
        .toUpperCase();
const shortDate = (iso) =>
    iso
        ? new Date(iso).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
        : '—';

function timeAgo(iso) {
    const then = new Date(iso).getTime();
    const diff = Date.now() - then;
    const steps = [
        [31536000000, 'year'],
        [2592000000, 'month'],
        [604800000, 'week'],
        [86400000, 'day'],
        [3600000, 'hour'],
        [60000, 'minute'],
    ];
    for (const [ms, label] of steps) {
        if (Math.abs(diff) >= ms) {
            const n = Math.floor(Math.abs(diff) / ms);
            return diff >= 0 ? `${n} ${label}${n > 1 ? 's' : ''} ago` : `in ${n} ${label}${n > 1 ? 's' : ''}`;
        }
    }
    return 'just now';
}

/* ------------------------------------------------------------
   Icons
   ------------------------------------------------------------ */
const ICONS = {
    grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
    building: '<path d="M3 21h18"/><path d="M5 21V7l7-4v18"/><path d="M19 21V11l-7-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/>',
    card: '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/>',
    layers: '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    chart: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="3" y1="20" x2="21" y2="20"/>',
    inbox: '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    search: '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    sun: '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
    moon: '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
    menu: '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
    'panel-left': '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/>',
    'chevron-down': '<polyline points="6 9 12 15 18 9"/>',
    'chevron-right': '<polyline points="9 18 15 12 9 6"/>',
    bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    star: '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>',
    plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    globe: '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    dollar: '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    'trending-up': '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    mail: '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>',
    check: '<polyline points="20 6 9 17 4 12"/>',
    'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    alert: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    x: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    edit: '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
    trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m5 0V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2"/>',
    refresh: '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
    external: '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    zap: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'file-text': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    message: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'arrow-left': '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
    save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
    key: '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
    activity: '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
};
const FILLED = new Set(['star', 'zap']);

function icon(name, attrs = '') {
    const body = ICONS[name] || ICONS.alert;
    const fill = FILLED.has(name) ? 'currentColor' : 'none';
    return `<svg viewBox="0 0 24 24" fill="${fill}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ${attrs}>${body}</svg>`;
}

/* ------------------------------------------------------------
   Charts (same maths as the PHP helpers)
   ------------------------------------------------------------ */
function niceMax(max) {
    if (max <= 0) return 1;
    const exp = Math.floor(Math.log10(max));
    const pow = Math.pow(10, exp);
    const f = max / pow;
    const steps = [1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10];
    return (steps.find((s) => f <= s) || 10) * pow;
}

function axisValue(v, format) {
    v = Number(v);
    if (format === 'money') return moneyShort(v);
    if (format === 'percent') return v.toFixed(1).replace(/\.0$/, '') + '%';
    if (format === 'decimal') return v.toFixed(1);
    if (Math.abs(v) >= 1000) return (v / 1000).toFixed(1) + 'k';
    return String(Math.round(v));
}

function sparkline(values, w = 88, h = 30) {
    values = values.map(Number);
    if (values.length < 2) return '';
    const max = Math.max(...values);
    const min = Math.min(...values);
    const range = max - min || 1;
    const stepX = w / (values.length - 1);
    const pts = values.map((v, i) => [+(i * stepX).toFixed(2), +(h - 2 - ((v - min) / range) * (h - 5)).toFixed(2)]);
    let line = `M ${pts[0][0]} ${pts[0][1]}`;
    for (let i = 1; i < pts.length; i++) {
        const [px, py] = pts[i - 1];
        const [cx, cy] = pts[i];
        const mx = (px + cx) / 2;
        line += ` Q ${px} ${py} ${mx} ${(py + cy) / 2} T ${cx} ${cy}`;
    }
    const area = `${line} L ${w} ${h} L 0 ${h} Z`;
    return `<svg class="sa-spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true"><path class="area" d="${area}"/><path class="line" d="${line}"/></svg>`;
}

function lineChart(labels, series, opts = {}) {
    const n = labels.length;
    if (!n) return '<div class="sa-empty"><p>No data to chart yet.</p></div>';
    const height = opts.height || 260;
    const format = opts.format || 'number';
    const padL = 46, padR = 16, padT = 18, padB = 30, W = 760;
    const plotW = W - padL - padR;
    const plotH = height - padT - padB;
    const all = series.flatMap((s) => s.values.map(Number));
    const max = niceMax(Math.max(...all, 0));
    const min = Math.min(0, ...all);
    const X = (i) => (n > 1 ? padL + (i * plotW) / (n - 1) : padL + plotW / 2);
    const Y = (v) => padT + plotH - ((v - min) / (max - min)) * plotH;

    let svg = '<div class="sa-chart" data-sa-chart="line">';
    svg += `<svg viewBox="0 0 ${W} ${height}" role="img" aria-label="Trend chart">`;
    svg += '<defs><linearGradient id="saAreaGradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#c2f542" stop-opacity="0.42"/><stop offset="100%" stop-color="#c2f542" stop-opacity="0"/></linearGradient></defs>';

    for (let t = 0; t <= 4; t++) {
        const v = min + ((max - min) * t) / 4;
        const y = Y(v).toFixed(1);
        svg += `<line class="grid-line" x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}"/>`;
        svg += `<text class="axis-label" x="${padL - 9}" y="${(Y(v) + 3.5).toFixed(1)}" text-anchor="end">${esc(axisValue(v, format))}</text>`;
    }
    const skip = n > 14 ? Math.ceil(n / 8) : 1;
    labels.forEach((l, i) => {
        if (i % skip !== 0 && i !== n - 1) return;
        svg += `<text class="axis-label" x="${X(i).toFixed(1)}" y="${height - 8}" text-anchor="middle">${esc(l)}</text>`;
    });

    series.forEach((s, si) => {
        const values = s.values.map(Number);
        const cls = si === 0 && !s.dashed ? 'line-stroke' : 'line-stroke-2';
        let line = values.map((v, i) => `${i === 0 ? 'M' : 'L'} ${X(i).toFixed(1)} ${Y(v).toFixed(1)}`).join(' ');
        if (si === 0 && !s.dashed) {
            svg += `<path class="area-fill" d="${line} L ${X(n - 1).toFixed(1)} ${padT + plotH} L ${X(0).toFixed(1)} ${padT + plotH} Z"/>`;
        }
        svg += `<path class="${cls}" d="${line}" style="--sa-dash:${2400 + n * 60}"/>`;
        values.forEach((v, i) => {
            const tip = `${labels[i]}: ${axisValue(v, s.format || format)}`;
            const tx = X(i).toFixed(1);
            const ty = Y(v).toFixed(1);
            const tipW = Math.max(58, tip.length * 5.6 + 16);
            const tipX = Math.min(Math.max(tx - tipW / 2, 2), W - tipW - 2);
            const tipY = Math.max(ty - 34, 2);
            svg += `<g class="point"><circle class="dot" cx="${tx}" cy="${ty}" r="3.6"/><g class="tip"><rect class="tip-bg" x="${tipX.toFixed(1)}" y="${tipY.toFixed(1)}" width="${tipW.toFixed(1)}" height="22" rx="7"/><text class="tip-text" x="${(tipX + tipW / 2).toFixed(1)}" y="${(tipY + 15).toFixed(1)}" text-anchor="middle">${esc(tip)}</text></g></g>`;
        });
    });

    svg += '</svg></div>';
    return svg;
}

function barChart(items, opts = {}) {
    if (!items.length) return '<div class="sa-empty"><p>No data to chart yet.</p></div>';
    const height = opts.height || 230;
    const format = opts.format || 'number';
    const padL = 44, padR = 14, padT = 16, padB = 30, W = 720;
    const plotW = W - padL - padR;
    const plotH = height - padT - padB;
    const max = niceMax(Math.max(...items.map((i) => Number(i.value)), 1));
    const slot = plotW / items.length;
    const barW = Math.min(38, slot * 0.56);

    let svg = '<div class="sa-chart" data-sa-chart="bars">';
    svg += `<svg viewBox="0 0 ${W} ${height}" role="img" aria-label="Bar chart">`;
    for (let t = 0; t <= 4; t++) {
        const v = (max * t) / 4;
        const y = (padT + plotH - (v / max) * plotH).toFixed(1);
        svg += `<line class="grid-line" x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}"/>`;
        svg += `<text class="axis-label" x="${padL - 9}" y="${(Number(y) + 3.5).toFixed(1)}" text-anchor="end">${esc(axisValue(v, format))}</text>`;
    }
    items.forEach((it, i) => {
        const cx = padL + slot * i + slot / 2;
        const v = Number(it.value);
        const h = v > 0 ? Math.max(3, (v / max) * plotH) : 0;
        svg += `<g class="point"><rect class="bar" x="${(cx - barW / 2).toFixed(1)}" y="${(padT + plotH - h).toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="5" style="animation-delay:${(i * 0.045).toFixed(2)}s"><title>${esc(it.label + ' — ' + axisValue(v, format))}</title></rect></g>`;
        svg += `<text class="axis-label" x="${cx.toFixed(1)}" y="${height - 8}" text-anchor="middle">${esc(it.label)}</text>`;
    });
    svg += '</svg></div>';
    return svg;
}

function donut(segments, center = {}, size = 168) {
    const segs = segments.filter((s) => Number(s.value) > 0);
    const total = segs.reduce((a, s) => a + Number(s.value), 0);
    if (!total) return '<div class="sa-empty"><p>Nothing to show yet.</p></div>';
    const stroke = 17;
    const r = size / 2 - stroke / 2 - 2;
    const c = 2 * Math.PI * r;
    let offset = 0;
    let svg = '<div class="sa-donut-wrap">';
    svg += `<div class="sa-donut" style="width:${size}px;height:${size}px">`;
    svg += `<svg viewBox="0 0 ${size} ${size}" role="img" aria-label="Distribution">`;
    svg += `<circle class="track" cx="${size / 2}" cy="${size / 2}" r="${r.toFixed(2)}"/>`;
    segs.forEach((s, i) => {
        const len = (Number(s.value) / total) * c;
        const dash = Math.max(len - 2.5, 0.5);
        svg += `<circle class="seg" cx="${size / 2}" cy="${size / 2}" r="${r.toFixed(2)}" stroke="${s.color}" stroke-dasharray="${dash.toFixed(2)} ${(c - dash).toFixed(2)}" stroke-dashoffset="${(-offset).toFixed(2)}" style="animation-delay:${(i * 0.09).toFixed(2)}s"><title>${esc(s.label + ': ' + s.value)}</title></circle>`;
        offset += len;
    });
    svg += '</svg>';
    svg += `<div class="sa-donut-center"><strong>${esc(center.value != null ? center.value : num(total))}</strong><span>${esc(center.label || 'Total')}</span></div>`;
    svg += '</div><div class="sa-legend">';
    segs.forEach((s) => {
        svg += `<div class="sa-legend-row"><span class="sa-legend-dot" style="--dot:${s.color}"></span><span class="sa-legend-name">${esc(s.label)}</span><span class="sa-legend-val">${esc(s.display || num(s.value))}</span><span class="sa-legend-pct">${Math.round((s.value / total) * 100)}%</span></div>`;
    });
    svg += '</div></div>';
    return svg;
}

function barList(items) {
    if (!items.length) return '<div class="sa-empty"><p>Nothing to show yet.</p></div>';
    const max = Math.max(...items.map((i) => Number(i.value)), 1);
    return (
        '<div class="sa-bars">' +
        items
            .map((it, i) => {
                const w = Math.max(1.5, (Number(it.value) / max) * 100);
                return `<div class="sa-bar-row"><div class="sa-bar-head"><strong>${esc(it.label)}</strong><span>${esc(it.meta || num(it.value))}</span></div><div class="sa-bar-track"><i class="sa-bar-fill" style="--w:${w.toFixed(2)}%;--delay:${(i * 0.06).toFixed(2)}s${it.color ? ';--bar:' + it.color : ''}"></i></div></div>`;
            })
            .join('') +
        '</div>'
    );
}

function heatmap(cells, columns = 18) {
    const max = Math.max(...cells.map((c) => Number(c.value)), 1);
    return (
        `<div class="sa-heat" style="grid-template-columns:repeat(${columns},minmax(0,1fr))">` +
        cells
            .map((c) => {
                const level = c.value <= 0 ? 0 : Math.ceil((c.value / max) * 4);
                return `<span class="sa-heat-cell" data-level="${level}" title="${esc(c.label + ': ' + c.value)}"></span>`;
            })
            .join('') +
        '</div>'
    );
}

function starsRow(rating, showNumber = true) {
    const r = Number(rating);
    let html = `<span class="sa-stars" role="img" aria-label="${r.toFixed(1)} out of 5">`;
    for (let i = 1; i <= 5; i++) {
        html += `<span class="${i <= Math.round(r) ? 'on' : 'off'}">${icon('star')}</span>`;
    }
    html += '</span>';
    if (showNumber) html += `<span class="sa-star-num">${r.toFixed(1)}</span>`;
    return html;
}

function statusBadge(status) {
    const map = {
        active: 'sa-badge-active',
        trial: 'sa-badge-trial',
        inactive: 'sa-badge-inactive',
        cancelled: 'sa-badge-cancelled',
        pending: 'sa-badge-pending',
        contacted: 'sa-badge-contacted',
        converted: 'sa-badge-converted',
        rejected: 'sa-badge-rejected',
    };
    const label = status.charAt(0).toUpperCase() + status.slice(1);
    return `<span class="sa-badge ${map[status] || 'sa-badge-cancelled'}">${esc(label)}</span>`;
}

function renewalBadge(end, renew) {
    const days = Math.round((new Date(end) - new Date(new Date().toDateString())) / 86400000);
    if (days < 0) return [`<span class="sa-badge sa-badge-expired">Expired ${Math.abs(days)}d ago</span>`, 'expired'];
    if (days === 0) return ['<span class="sa-badge sa-badge-trial">Ends today</span>', 'due'];
    if (days <= 30) {
        const cls = renew ? 'sa-badge-trial' : 'sa-badge-expired';
        return [`<span class="sa-badge ${cls}">${days}d left${renew ? ' · auto-renew' : ''}</span>`, days <= 7 ? 'due' : 'soon'];
    }
    return [`<span class="sa-badge sa-badge-active">${days}d left</span>`, 'ok'];
}

function delta(v, suffix = '%', invert = false, d = 1) {
    v = Number(v);
    if (Math.abs(v) < 0.05) {
        return `<span class="sa-delta sa-delta-flat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>${v.toFixed(d)}${suffix}</span>`;
    }
    const up = v > 0;
    const cls = invert ? (up ? 'sa-delta-down' : 'sa-delta-up') : up ? 'sa-delta-up' : 'sa-delta-down';
    const arrow = up
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    return `<span class="sa-delta ${cls}">${arrow}${up ? '+' : ''}${v.toFixed(d)}${suffix}</span>`;
}

module.exports = {
    ROOT, OUT, CUR,
    months, monthsLong, trend, tenants, plans, quotes, topCompanies, activity,
    stars, totalRatings, avgRating, statusCounts, mrr, heatCells,
    esc, num, money, moneyShort, pct, initials, shortDate, timeAgo,
    icon, sparkline, lineChart, barChart, donut, barList, heatmap,
    starsRow, statusBadge, renewalBadge, delta,
};
