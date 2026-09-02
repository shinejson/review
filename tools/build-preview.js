#!/usr/bin/env node
/**
 * Renders the static preview pages into .preview/ using the shared
 * data + chart helpers from preview-data.js. Run:
 *
 *      node tools/build-preview.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const D = require('./preview-data.js');

const {
    ROOT, OUT, esc, num, money, moneyShort, pct, initials, shortDate, timeAgo,
    icon, sparkline, lineChart, barChart, donut, barList, heatmap,
    starsRow, statusBadge, renewalBadge, delta,
    trend, tenants, plans, quotes, topCompanies, activity, stars,
    totalRatings, avgRating, statusCounts, mrr, heatCells,
} = D;

/* ------------------------------------------------------------
   Shell components
   ------------------------------------------------------------ */
const NAV = [
    { section: 'Overview' },
    { key: 'dashboard', label: 'Dashboard', href: 'index.php', icon: 'grid' },
    { key: 'analytics', label: 'Analytics', href: 'analytics.php', icon: 'chart' },
    { section: 'Billing' },
    { key: 'tenants', label: 'Tenants', href: 'tenants.php', icon: 'building', badge: tenants.length },
    { key: 'subscriptions', label: 'Subscriptions', href: 'subscriptions.php', icon: 'card', badge: tenants.filter((t) => t.days <= 30).length, alert: true },
    { key: 'plans', label: 'Plans', href: 'plans.php', icon: 'layers' },
    { key: 'quotes', label: 'Quote Requests', href: 'quote_requests.php', icon: 'inbox', badge: quotes.filter((q) => q.status === 'pending').length, alert: true },
    { section: 'System' },
    { key: 'settings', label: 'Settings', href: 'settings.php', icon: 'settings' },
];

const ADMIN = 'superadmin';

function sidebar(active) {
    return `
    <aside class="sa-sidebar">
        <a class="sa-brand" href="../index.php" title="Optibiz">
            <span class="sa-brand-badge">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            </span>
            <span class="sa-brand-name"><strong>Optibiz</strong><span>Control Center</span></span>
        </a>

        <nav class="sa-nav" aria-label="Super admin">
${NAV.map((item) => {
    if (item.section) {
        return `            <div class="sa-nav-label"><span>${esc(item.section)}</span></div>`;
    }
    const badge = item.badge > 0
        ? `<span class="sa-nav-count${item.alert && item.key !== 'tenants' ? ' is-alert' : ''}">${item.badge}</span>`
        : '';
    return `            <a href="${item.href}" data-label="${esc(item.label)}"${item.key === active ? ' class="active" aria-current="page"' : ''}>${icon(item.icon)}<span>${esc(item.label)}</span>${badge}</a>`;
}).join('\n')}
        </nav>

        <div class="sa-side-foot">
            <div class="sa-user">
                <span class="sa-user-avatar">${esc(initials(ADMIN))}</span>
                <span class="sa-user-meta"><strong>${esc(ADMIN)}</strong><span>Super admin</span></span>
            </div>
            <a class="sa-logout" href="logout.php" data-label="Sign out" data-sa-confirm="Sign out of the control center?">
                ${icon('logout')}<span>Sign out</span>
            </a>
        </div>
    </aside>`;
}

function topbar(opts) {
    const pending = quotes.filter((q) => q.status === 'pending').length;
    return `
        <header class="sa-topbar">
            <button type="button" class="sa-icon-btn sa-burger" data-sa-burger aria-label="Open navigation" aria-expanded="false">${icon('menu')}</button>
            <button type="button" class="sa-icon-btn sa-collapse-btn" data-sa-collapse aria-label="Toggle sidebar" title="Toggle sidebar">${icon('panel-left')}</button>

            <div class="sa-topbar-title">
                <h1>${esc(opts.heading)}</h1>
                <p>${esc(opts.subtitle)}</p>
            </div>

${opts.searchTarget ? `            <div class="sa-search">
                ${icon('search')}
                <input type="search" placeholder="${esc(opts.searchPlaceholder || 'Search…')}" aria-label="Search" data-sa-search="${opts.searchTarget}" autocomplete="off">
                <kbd>/</kbd>
            </div>` : ''}

            <div class="sa-topbar-actions">
                <a class="sa-icon-btn" href="quote_requests.php" title="${pending} pending quote request(s)" aria-label="${pending} pending quote requests" style="position:relative">
                    ${icon('bell')}
                    <span style="position:absolute;top:-3px;right:-3px;min-width:17px;height:17px;padding:0 4px;border-radius:99px;background:var(--sa-danger);color:#fff;font-size:10px;font-weight:800;display:grid;place-items:center;border:2px solid var(--sa-bg)">${pending}</span>
                </a>

                <button type="button" class="sa-theme-toggle" data-sa-theme aria-pressed="false" aria-label="Switch theme" title="Switch theme">
                    <span class="sa-theme-thumb">
                        <span class="icon-moon">${icon('moon')}</span>
                        <span class="icon-sun">${icon('sun')}</span>
                    </span>
                </button>

                <div class="sa-menu-wrap" data-sa-menu>
                    <button type="button" class="sa-avatar-btn" data-sa-menu-trigger aria-haspopup="true" aria-expanded="false">
                        <span class="sa-avatar">${esc(initials(ADMIN))}</span>
                        <span>${esc(ADMIN)}</span>
                        ${icon('chevron-down')}
                    </button>
                    <div class="sa-menu" role="menu">
                        <div class="sa-menu-head"><strong>${esc(ADMIN)}</strong><span>superadmin@optibiz.com</span></div>
                        <a href="settings.php" role="menuitem">${icon('settings')} Platform settings</a>
                        <a href="analytics.php" role="menuitem">${icon('chart')} Analytics</a>
                        <a href="../index.php" target="_blank" rel="noopener" role="menuitem">${icon('globe')} View public site</a>
                        <a class="is-danger" href="logout.php" role="menuitem" data-sa-confirm="Sign out of the control center?">${icon('logout')} Sign out</a>
                    </div>
                </div>
            </div>
        </header>`;
}

function foot() {
    return `
        <footer class="sa-foot">
            <span>&copy; ${new Date().getFullYear()} Optibiz &middot; Super admin control center</span>
            <span class="sa-foot-links">
                <a href="../index.php" target="_blank" rel="noopener">Public site</a>
                <a href="../admin/index.php">Tenant admin</a>
                <a href="#saContent" data-sa-totop>Back to top</a>
            </span>
        </footer>`;
}

function page(opts) {
    const crumbs = (opts.crumbs || [])
        .map((c, i, arr) =>
            i === arr.length - 1
                ? `<span>${esc(c)}</span>`
                : `<a href="${opts.crumbHref || 'index.php'}">${esc(c)}</a>${icon('chevron-right')}`
        )
        .join('\n            ');

    return `<!DOCTYPE html>
<html lang="en" data-theme="dark" class="sa-preload">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>${esc(opts.title)} &middot; Optibiz</title>
    <style>html.sa-preload *, html.sa-preload *::before, html.sa-preload *::after { transition: none !important; }</style>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/superadmin.css">
</head>
<body class="sa-body">
<script>
(function () {
    try {
        var t = localStorage.getItem('optibiz-sa-theme');
        if (t === 'light' || t === 'dark') { document.documentElement.setAttribute('data-theme', t); }
    } catch (e) {}
})();
</script>
<div class="sa-app" id="saApp">
${sidebar(opts.active)}
    <div class="sa-scrim" data-sa-scrim></div>
    <div class="sa-main">
${topbar(opts)}
        <main class="sa-content" id="saContent">

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            ${crumbs}
        </div>
        <h2>${esc(opts.h2)}</h2>
        <p>${opts.p || ''}</p>
    </div>
    <div class="sa-head-actions">
${opts.actions || ''}
    </div>
</div>

${opts.body}

        </main>
${foot()}
    </div>
</div>
<script src="../assets/js/superadmin.js"></script>
</body>
</html>
`;
}

/* ------------------------------------------------------------
   Shared bits
   ------------------------------------------------------------ */
function kpi({ label, icon: ic, value, foot: f, note, accent, soft, line, spark, deltaHtml, small }) {
    return `    <article class="sa-card sa-kpi" style="--kpi-accent:var(${accent});--kpi-soft:var(${soft});--kpi-line:var(${line})">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">${esc(label)}</span>
            <span class="sa-kpi-icon">${icon(ic)}</span>
        </div>
        <div class="sa-kpi-value">${value}${small ? `<small>${esc(small)}</small>` : ''}</div>
        <div class="sa-kpi-foot">
            ${deltaHtml || f || `<span class="sa-kpi-note">${note || ''}</span>`}
            ${spark || ''}
        </div>
        ${deltaHtml || f ? `<div class="sa-kpi-note">${note || ''}</div>` : ''}
    </article>`;
}

function flash(type, message) {
    const cls = type === 'error' ? 'sa-alert-error' : type === 'warning' ? 'sa-alert-warning' : 'sa-alert-success';
    const ic = type === 'success' ? 'check-circle' : 'alert';
    return `<div class="sa-alert ${cls}" data-sa-alert data-sa-autohide="7000" role="status">
    ${icon(ic)}
    <div>${esc(message)}</div>
</div>`;
}

function tenantRow(t, opts = {}) {
    const [badge] = renewalBadge(t.end, t.renew);
    return `                <tr data-filterable data-search="${esc([t.id, t.company, t.email, t.username, t.plan, t.status].join(' ').toLowerCase())}">
                    <td class="num sa-faint" data-sort-value="${t.id}">#${t.id}</td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar">${esc(initials(t.company))}</span>
                            <span class="sa-cell-text"><strong>${esc(t.company)}</strong><span>login: ${esc(t.username)}</span></span>
                        </div>
                    </td>
                    <td><span class="sa-cell-text"><strong style="font-weight:500">${esc(t.email)}</strong><span>${esc(t.phone || 'No phone')}</span></span></td>
                    <td><span class="sa-badge sa-badge-plan">${esc(t.plan)}</span></td>
                    <td class="num" data-sort-value="${t.price}" data-export-value="${t.price}">${esc(money(t.price))}</td>
                    <td data-sort-value="${esc(t.status)}">
                        <form method="POST" action="tenants.php" style="display:inline">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="tenant_id" value="${t.id}">
                            <select class="sa-inline-select" name="status" aria-label="Status for ${esc(t.company)}" disabled>
${['trial', 'active', 'inactive', 'cancelled'].map((s) => `                                <option value="${s}"${t.status === s ? ' selected' : ''}>${s.charAt(0).toUpperCase() + s.slice(1)}</option>`).join('\n')}
                            </select>
                        </form>
                    </td>
                    <td class="num" data-sort-value="${t.companies}">${esc(num(t.companies))}</td>
                    <td data-sort-value="${t.end}">${badge}</td>
                    <td data-sort-value="${t.created}">${esc(shortDate(t.created))}</td>
                    <td data-no-export>
                        <div class="sa-row-actions">
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenant_details.php?id=${t.id}" title="Open ${esc(t.company)}">${icon('eye')}</a>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" title="Edit tenant" data-sa-edit-tenant data-id="${t.id}" data-company="${esc(t.company)}" data-email="${esc(t.email)}" data-phone="${esc(t.phone || '')}" data-plan="${t.plan_id}" data-price="${t.price}" data-status="${t.status}" data-renew="${t.renew}" data-start="2026-01-01" data-end="${t.end}">${icon('edit')}</button>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" title="Reset tenant password" data-sa-password-tenant data-id="${t.id}" data-company="${esc(t.company)}">${icon('key')}</button>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-danger" title="Delete tenant (demo)">${icon('trash')}</button>
                        </div>
                    </td>
                </tr>`;
}

const planPalette = ['var(--sa-lime)', 'var(--sa-info)', 'var(--sa-violet)', 'var(--sa-warning)', 'var(--sa-success)'];
const planSegments = plans.map((p, i) => ({
    label: p.name,
    value: p.tenants + p.trials,
    display: money(p.mrr) + ' MRR',
    color: planPalette[i % planPalette.length],
}));
const statusSegments = [
    { label: 'Active', value: statusCounts.active, color: 'var(--sa-success)' },
    { label: 'Trial', value: statusCounts.trial, color: 'var(--sa-warning)' },
    { label: 'Inactive', value: statusCounts.inactive, color: 'var(--sa-danger)' },
    { label: 'Cancelled', value: statusCounts.cancelled, color: 'var(--sa-faint)' },
];
const starItems = Object.entries(stars).map(([star, count], i) => ({
    label: `${star} star`,
    value: count,
    meta: `${num(count)} · ${Math.round((count / totalRatings) * 100)}%`,
    color: star >= 4
        ? 'linear-gradient(90deg,#a8e030,#c2f542)'
        : star === '3'
        ? 'linear-gradient(90deg,#f59e0b,#fbbf24)'
        : 'linear-gradient(90deg,#ef4444,#f87171)',
}));
const topBars = topCompanies.map((c) => ({
    label: c.name,
    value: c.ratings,
    meta: `${num(c.ratings)} ratings · ${c.avg.toFixed(1)}★`,
}));

const labels = trend.map((t) => t.label);
const mrrSeries = trend.map((t) => t.mrr);
const newSeries = trend.map((t) => t.new);
const ratingSeries = trend.map((t) => t.ratings);
const avgSeries = trend.map((t) => t.avg);
const sparkMrr = sparkline(mrrSeries);
const sparkTenants = sparkline(trend.map((t) => t.tenants));
const sparkRatings = sparkline(heatCells.slice(-28).map((c) => c.value));

const mrrDelta = ((mrrSeries[mrrSeries.length - 1] - mrrSeries[mrrSeries.length - 2]) / mrrSeries[mrrSeries.length - 2]) * 100;
const tenantDelta = ((trend[11].tenants - trend[10].tenants) / trend[10].tenants) * 100;
const ratingDelta = 12.4;
const arr = mrr * 12;
const arpu = mrr / statusCounts.active;

/* ------------------------------------------------------------
   PAGE: dashboard (index.php)
   ------------------------------------------------------------ */
const dueSoon = tenants.filter((t) => (t.status === 'active' || t.status === 'trial') && t.days <= 30);
const pendingQuotes = quotes.filter((q) => q.status === 'pending');

const dashboardBody = `
<div class="sa-alert sa-alert-warning" data-sa-alert>
    ${icon('alert')}
    <div>
        <strong>Needs your attention</strong>
        <a href="quote_requests.php" style="color:inherit;font-weight:700;text-decoration:underline">${pendingQuotes.length} pending quote requests</a>
        &middot; <a href="subscriptions.php" style="color:inherit;font-weight:700;text-decoration:underline">${dueSoon.length} renewing or expiring within 30 days</a>.
    </div>
</div>

<div class="sa-grid sa-kpis sa-anim">
${kpi({
    label: 'Monthly recurring revenue', icon: 'dollar',
    value: `<span data-sa-count="${mrr.toFixed(2)}" data-sa-decimals="2" data-sa-prefix="$">${esc(money(mrr))}</span>`,
    deltaHtml: delta(mrrDelta), spark: sparkMrr,
    note: `vs last month · ${esc(money(arr, 0))} ARR`,
    accent: '--sa-lime', soft: '--sa-accent-soft', line: '--sa-accent-line',
})}
${kpi({
    label: 'Total tenants', icon: 'building',
    value: `<span data-sa-count="${tenants.length}">${tenants.length}</span>`,
    deltaHtml: delta(tenantDelta), spark: sparkTenants,
    note: '3 joined in the last 30 days',
    accent: '--sa-info', soft: '--sa-info-soft', line: '--sa-info-line',
})}
${kpi({
    label: 'Active subscriptions', icon: 'card',
    value: `<span data-sa-count="${statusCounts.active}">${statusCounts.active}</span>`,
    f: `<span class="sa-pill">${Math.round((statusCounts.active / tenants.length) * 100)}% of base paying</span>`,
    spark: sparkMrr,
    note: `${statusCounts.trial} on trial · ${statusCounts.cancelled} cancelled`,
    accent: '--sa-success', soft: '--sa-success-soft', line: '--sa-success-line',
})}
${kpi({
    label: 'Ratings collected', icon: 'star',
    value: `<span data-sa-count="${totalRatings}">${num(totalRatings)}</span>`,
    deltaHtml: delta(ratingDelta), spark: sparkRatings,
    note: '', accent: '--sa-warning', soft: '--sa-warning-soft', line: '--sa-warning-line',
})}
${kpi({
    label: 'Quote pipeline', icon: 'inbox',
    value: `<span data-sa-count="${pendingQuotes.length}">${pendingQuotes.length}</span>`,
    small: 'pending',
    f: `<span class="sa-pill">${quotes.length} total requests</span><a class="sa-btn sa-btn-sm sa-btn-ghost" href="quote_requests.php">Review</a>`,
    note: 'Inbound leads from the public “Get started” form',
    accent: '--sa-violet', soft: '--sa-violet-soft', line: '--sa-violet-line',
})}
</div>

<div class="sa-grid sa-split-2-1">
    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Revenue &amp; tenant growth</h3><p>Cumulative MRR against newly signed tenants, last 12 months</p></div>
            <div class="sa-card-head-actions">
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-lime);display:inline-block"></i> MRR</span>
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-info);display:inline-block"></i> New tenants</span>
            </div>
        </div>
        <div class="sa-card-pad">
            ${lineChart(labels, [
                { name: 'MRR', values: mrrSeries, format: 'money' },
                { name: 'New tenants', values: newSeries, format: 'number', dashed: true },
            ], { height: 268, format: 'money' })}
        </div>
        <div class="sa-card-foot">
            <span>${esc(money(mrr))} MRR today &middot; ${esc(money(arpu))} average per paying tenant</span>
            <a href="analytics.php">Full analytics ${icon('chevron-right')}</a>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Plan distribution</h3><p>Active and trial tenants per plan</p></div></div>
        <div class="sa-card-pad">
            ${donut(planSegments, { value: num(statusCounts.active + statusCounts.trial), label: 'Subscribed' })}
        </div>
        <div class="sa-card-foot">
            <span>${plans.length} plans on sale</span>
            <a href="plans.php">Manage plans ${icon('chevron-right')}</a>
        </div>
    </section>
</div>

<div class="sa-grid sa-split-2-1 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Recent tenants</h3><p>Newest companies onboarded to the platform</p></div>
            <div class="sa-card-head-actions"><a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenants.php">View all</a></div>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table" id="tenantsTable" data-sa-sortable-table>
                <thead>
                    <tr>
                        <th data-sa-sort="0">Company</th>
                        <th data-sa-sort="1">Plan</th>
                        <th data-sa-sort="2" data-type="num">MRR</th>
                        <th data-sa-sort="3">Status</th>
                        <th data-sa-sort="4" data-type="date">Joined</th>
                        <th data-no-export><span class="sa-sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
${tenants.slice(0, 8).map((t) => `                    <tr data-filterable data-search="${esc((t.company + ' ' + t.email + ' ' + t.plan + ' ' + t.status).toLowerCase())}">
                        <td>
                            <div class="sa-cell-main">
                                <span class="sa-cell-avatar">${esc(initials(t.company))}</span>
                                <span class="sa-cell-text"><strong>${esc(t.company)}</strong><span>${esc(t.email)}</span></span>
                            </div>
                        </td>
                        <td><span class="sa-badge sa-badge-plan">${esc(t.plan)}</span></td>
                        <td class="num" data-sort-value="${t.price}" data-export-value="${t.price}">${esc(money(t.price))}</td>
                        <td>${statusBadge(t.status)}</td>
                        <td data-sort-value="${t.created}"><span title="${esc(shortDate(t.created))}">${esc(timeAgo(t.created))}</span></td>
                        <td data-no-export><div class="sa-row-actions"><a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenant_details.php?id=${t.id}" title="Open tenant">${icon('eye')} View</a></div></td>
                    </tr>`).join('\n')}
                </tbody>
            </table>
        </div>
        <div class="sa-empty" id="tenantsTableEmpty" hidden>${icon('search')}<strong>No matches</strong><p>Try a different company name, email or plan.</p></div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Renewals &amp; churn risk</h3><p>Subscriptions ending within 30 days</p></div>
            <span class="sa-pill">${dueSoon.length} flagged</span>
        </div>
        <div class="sa-list">
${dueSoon.slice(0, 6).map((t) => {
    const [badge, kind] = renewalBadge(t.end, t.renew);
    return `            <a class="sa-list-item" href="tenant_details.php?id=${t.id}">
                <span class="sa-list-icon ${kind === 'expired' || kind === 'due' ? 'is-danger' : 'is-warning'}">${icon(kind === 'ok' ? 'calendar' : 'clock')}</span>
                <span class="sa-list-body"><strong>${esc(t.company)}</strong><span>${esc(t.plan + ' · ' + money(t.price) + '/mo')}</span></span>
                <span class="sa-list-side">${badge}<strong style="margin-top:4px">${esc(shortDate(t.end))}</strong></span>
            </a>`;
}).join('\n')}
        </div>
        <div class="sa-card-foot">
            <span>${tenants.filter((t) => t.days < 0).length} already expired</span>
            <a href="subscriptions.php">All subscriptions ${icon('chevron-right')}</a>
        </div>
    </section>
</div>

<div class="sa-grid sa-cols-3 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Top rated companies</h3><p>Most reviewed across all tenants</p></div></div>
        <div class="sa-card-pad">${barList(topBars)}</div>
        <div class="sa-card-foot"><span>184 companies listed</span><a href="analytics.php">See ranking ${icon('chevron-right')}</a></div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Rating breakdown</h3><p>${starsRow(avgRating)}</p></div></div>
        <div class="sa-card-pad">${barList(starItems)}</div>
        <div class="sa-card-foot"><span>${Math.round((stars[5] / totalRatings) * 100)}% are 5-star</span><span>${num(104)} in the last 30 days</span></div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Tenant health</h3><p>Where the ${tenants.length} tenants stand</p></div></div>
        <div class="sa-card-pad">
            ${donut(statusSegments, { value: num(tenants.length), label: 'Tenants' }, 150)}
            <div class="sa-section-title" style="margin:22px 0 11px">Rating activity</div>
            ${heatmap(heatCells.slice(-54), 18)}
            <p class="sa-faint" style="font-size:11.6px;margin-top:9px">Last 54 days &middot; darker means more ratings</p>
        </div>
    </section>
</div>

<div class="sa-grid sa-split-2-1 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Quote requests</h3><p>Inbound interest from the public “Get started” wizard</p></div>
            <div class="sa-card-head-actions"><a class="sa-btn sa-btn-sm sa-btn-ghost" href="quote_requests.php">Open pipeline</a></div>
        </div>
        <div class="sa-list">
${quotes.slice(0, 5).map((q) => `            <a class="sa-list-item" href="quote_requests.php?id=${q.id}">
                <span class="sa-list-icon ${q.status === 'converted' ? 'is-success' : q.status === 'rejected' ? 'is-danger' : 'is-info'}">${icon(q.status === 'converted' ? 'check-circle' : 'file-text')}</span>
                <span class="sa-list-body"><strong>${esc(q.company)}</strong><span>${esc(q.contact + ' · ' + q.email)}</span></span>
                <span class="sa-list-side">${statusBadge(q.status)}<strong style="margin-top:4px">${esc(q.plan)}</strong></span>
            </a>`).join('\n')}
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Activity feed</h3><p>Latest events across the platform</p></div>
            <button type="button" class="sa-icon-btn" onclick="window.location.reload()" title="Refresh" aria-label="Refresh">${icon('refresh')}</button>
        </div>
        <div class="sa-list">
${activity.map((a) => `            <div class="sa-list-item">
                <span class="sa-list-icon ${a.type === 'rating' ? 'is-warning' : a.type === 'quote' ? 'is-info' : ''}">${icon(a.type === 'rating' ? 'star' : a.type === 'quote' ? 'message' : 'building')}</span>
                <span class="sa-list-body"><strong>${esc(a.title)}</strong><span>${esc(a.meta)}</span></span>
                <span class="sa-list-side">${esc(a.at)}</span>
            </div>`).join('\n')}
        </div>
    </section>
</div>
`;

/* ------------------------------------------------------------
   PAGE: tenants
   ------------------------------------------------------------ */
const tenantChips = [
    ['all', 'All tenants', tenants.length],
    ['active', 'Active', statusCounts.active],
    ['trial', 'Trial', statusCounts.trial],
    ['inactive', 'Inactive', statusCounts.inactive],
    ['cancelled', 'Cancelled', statusCounts.cancelled],
];

const tenantsBody = `
${flash('success', 'Volta Logistics was created with the login “volta_logistics”.')}

<section class="sa-card sa-mb">
    <div class="sa-filters">
        <div class="sa-chips">
${tenantChips.map(([key, label, count], i) => `            <a class="sa-chip${i === 0 ? ' active' : ''}" href="tenants.php?status=${key}" aria-pressed="${i === 0}">${esc(label)}<span class="count">${count}</span></a>`).join('\n')}
        </div>
        <form method="GET" action="tenants.php" style="margin-left:auto;display:flex;gap:8px;align-items:center" onsubmit="return false">
            <div class="sa-search" style="display:block;width:min(280px,52vw)">
                ${icon('search')}
                <input type="search" name="q" placeholder="Search name, email or login…" aria-label="Search tenants">
            </div>
            <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">Search</button>
        </form>
    </div>
</section>

<section class="sa-card">
    <div class="sa-card-head">
        <div><h3>All tenants</h3><p>${tenants.length} results</p></div>
        <div class="sa-card-head-actions"><span class="sa-pill">${icon('users')} 184 companies managed</span></div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="tenantsTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0" data-type="num">ID</th>
                    <th data-sa-sort="1">Company</th>
                    <th data-sa-sort="2">Contact</th>
                    <th data-sa-sort="3">Plan</th>
                    <th data-sa-sort="4" data-type="num">Price / mo</th>
                    <th data-sa-sort="5">Status</th>
                    <th data-sa-sort="6" data-type="num">Companies</th>
                    <th data-sa-sort="7" data-type="date">Renews</th>
                    <th data-sa-sort="8" data-type="date">Joined</th>
                    <th data-no-export><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
${tenants.map((t) => tenantRow(t)).join('\n')}
            </tbody>
        </table>
    </div>
    <div class="sa-empty" id="tenantsTableEmpty" hidden>${icon('search')}<strong>No matching tenants</strong><p>Nothing in this table matches the text in the top-right filter box.</p></div>
    <div class="sa-card-foot">
        <span>Showing ${tenants.length} of ${tenants.length} tenants</span>
        <span>Status changes save instantly &middot; use ${icon('key', 'style="width:12px;height:12px;vertical-align:-2px"')} to reset a tenant login</span>
    </div>
</section>

<dialog class="sa-dialog" id="tenantCreateDialog">
    <form method="POST" action="tenants.php" class="sa-form" onsubmit="return false">
        <div class="sa-dialog-head">
            <div><h3>Create a tenant</h3><p>This also creates the login the company will use at <span class="sa-mono">/admin/login.php</span>.</p></div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close">${icon('x')}</button>
        </div>
        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field"><label for="c_company">Company name *</label><input id="c_company" type="text" name="company_name" placeholder="e.g. Accra Consulting Ltd" required></div>
                <div class="sa-field"><label for="c_email">Billing email *</label><input id="c_email" type="email" name="email" placeholder="billing@company.com" required></div>
                <div class="sa-field"><label for="c_phone">Phone</label><input id="c_phone" type="tel" name="phone" placeholder="+233 …"></div>
                <div class="sa-field"><label for="c_plan">Plan</label><select id="c_plan" name="plan_id">${plans.map((p) => `<option value="${p.id}">${esc(p.name + ' — ' + money(p.price) + '/mo')}</option>`).join('')}</select></div>
                <div class="sa-field"><label for="c_status">Starts as</label><select id="c_status" name="subscription_status"><option value="trial">Trial</option><option value="active">Active (paying)</option><option value="inactive">Inactive</option></select></div>
                <div class="sa-field"><label for="c_months">Subscription length (months)</label><input id="c_months" type="number" name="trial_months" min="0" max="60" value="1"><span class="sa-hint">0 leaves the end date open.</span></div>
                <div class="sa-field" style="grid-column:1/-1"><label for="c_password">Temporary password *</label><input id="c_password" type="text" name="password" minlength="6" placeholder="At least 6 characters" required><span class="sa-hint">The tenant login name is generated from the company name.</span></div>
            </div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary">${icon('check')} Create tenant</button>
        </div>
    </form>
</dialog>

<dialog class="sa-dialog" id="tenantEditDialog">
    <form method="POST" action="tenants.php" class="sa-form" onsubmit="return false">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="tenant_id" id="e_id" value="">
        <div class="sa-dialog-head">
            <div><h3>Edit tenant</h3><p id="e_subtitle">Update the company record, plan and billing dates.</p></div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close">${icon('x')}</button>
        </div>
        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field"><label for="e_company">Company name *</label><input id="e_company" type="text" name="company_name" required></div>
                <div class="sa-field"><label for="e_email">Email *</label><input id="e_email" type="email" name="email" required></div>
                <div class="sa-field"><label for="e_phone">Phone</label><input id="e_phone" type="tel" name="phone"></div>
                <div class="sa-field"><label for="e_plan">Plan</label><select id="e_plan" name="plan_id">${plans.map((p) => `<option value="${p.id}" data-price="${p.price}">${esc(p.name)}</option>`).join('')}<option value="0">No plan</option></select></div>
                <div class="sa-field"><label for="e_price">Price / month</label><input id="e_price" type="number" step="0.01" min="0" name="subscription_price" value="0"></div>
                <div class="sa-field"><label for="e_status">Status</label><select id="e_status" name="subscription_status">${['trial', 'active', 'inactive', 'cancelled'].map((s) => `<option value="${s}">${s.charAt(0).toUpperCase() + s.slice(1)}</option>`).join('')}</select></div>
                <div class="sa-field"><label for="e_start">Start date</label><input id="e_start" type="date" name="subscription_start_date"></div>
                <div class="sa-field"><label for="e_end">End date</label><input id="e_end" type="date" name="subscription_end_date"></div>
                <div class="sa-field" style="grid-column:1/-1"><label class="sa-switch"><input type="checkbox" name="auto_renew" id="e_renew" value="1"><span class="sa-switch-track"></span><span class="sa-switch-text">Auto-renew at the end date</span></label></div>
            </div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary">${icon('save')} Save changes</button>
        </div>
    </form>
</dialog>

<dialog class="sa-dialog" id="tenantPasswordDialog" style="width:min(460px,calc(100vw - 32px))">
    <form method="POST" action="tenants.php" class="sa-form" onsubmit="return false">
        <div class="sa-dialog-head">
            <div><h3>Reset tenant password</h3><p id="p_subtitle">Set a new login password for this tenant.</p></div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close">${icon('x')}</button>
        </div>
        <div class="sa-dialog-body">
            <div class="sa-field"><label for="p_password">New password</label><input id="p_password" type="text" name="password" minlength="6" placeholder="At least 6 characters" required><span class="sa-hint">Share it with the tenant over a secure channel.</span></div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary">${icon('key')} Update password</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    function open(sel) {
        var d = document.querySelector(sel);
        if (!d) return;
        if (typeof d.showModal === 'function') d.showModal();
        else { d.setAttribute('open', ''); d.classList.add('is-open-fallback'); }
    }
    document.querySelectorAll('[data-sa-edit-tenant]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.dataset;
            document.getElementById('e_id').value = d.id;
            document.getElementById('e_company').value = d.company;
            document.getElementById('e_email').value = d.email;
            document.getElementById('e_phone').value = d.phone;
            document.getElementById('e_plan').value = d.plan;
            document.getElementById('e_price').value = d.price;
            document.getElementById('e_status').value = d.status;
            document.getElementById('e_end').value = d.end || '';
            document.getElementById('e_renew').checked = d.renew === '1';
            document.getElementById('e_subtitle').textContent = 'Editing tenant #' + d.id + ' — ' + d.company;
            open('#tenantEditDialog');
        });
    });
    document.getElementById('e_plan').addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        if (opt && opt.getAttribute('data-price')) document.getElementById('e_price').value = opt.getAttribute('data-price');
    });
    document.querySelectorAll('[data-sa-password-tenant]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('p_subtitle').textContent = 'Set a new login password for ' + btn.dataset.company + '.';
            open('#tenantPasswordDialog');
        });
    });
})();
</script>
`;

/* ------------------------------------------------------------
   PAGE: subscriptions
   ------------------------------------------------------------ */
const dueCount = dueSoon.length;
const autoRenew = tenants.filter((t) => t.renew).length;
const subChips = [
    ['all', 'All', tenants.length],
    ['active', 'Active', statusCounts.active],
    ['trial', 'Trial', statusCounts.trial],
    ['due', 'Due in 30 days', dueCount],
    ['inactive', 'Inactive', statusCounts.inactive],
    ['cancelled', 'Cancelled', statusCounts.cancelled],
];

const subsBody = `
<div class="sa-grid sa-kpis sa-anim">
${kpi({ label: 'MRR', icon: 'dollar', value: esc(money(mrr)), note: `${esc(money(arpu))} average per paying tenant`, accent: '--sa-lime', soft: '--sa-accent-soft', line: '--sa-accent-line' })}
${kpi({ label: 'ARR', icon: 'trending-up', value: esc(money(arr, 0)), note: "Annualised run rate at today's MRR", accent: '--sa-info', soft: '--sa-info-soft', line: '--sa-info-line' })}
${kpi({ label: 'Due in 30 days', icon: 'clock', value: num(dueCount), note: `${tenants.filter((t) => t.days < 0).length} already past their end date`, accent: '--sa-warning', soft: '--sa-warning-soft', line: '--sa-warning-line' })}
${kpi({ label: 'Auto-renew on', icon: 'refresh', value: num(autoRenew), note: `${Math.round((autoRenew / tenants.length) * 100)}% of all tenants`, accent: '--sa-success', soft: '--sa-success-soft', line: '--sa-success-line' })}
</div>

<section class="sa-card sa-mb">
    <div class="sa-filters">
        <div class="sa-chips">
${subChips.map(([key, label, count], i) => `            <a class="sa-chip${i === 0 ? ' active' : ''}" href="subscriptions.php?view=${key}" aria-pressed="${i === 0}">${esc(label)}<span class="count">${count}</span></a>`).join('\n')}
        </div>
        <span class="sa-pill" style="margin-left:auto">${esc(money(mrr))} MRR in this view</span>
    </div>
</section>

<section class="sa-card">
    <div class="sa-card-head"><div><h3>All subscriptions</h3><p>Sorted by the closest renewal date first</p></div></div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="subsTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0">Tenant</th>
                    <th data-sa-sort="1">Plan</th>
                    <th data-sa-sort="2" data-type="num">Price / mo</th>
                    <th data-sa-sort="3">Status</th>
                    <th data-sa-sort="4" data-type="date">Started</th>
                    <th data-sa-sort="5" data-type="date">Ends</th>
                    <th data-sa-sort="6">Renewal</th>
                    <th data-sa-sort="7">Auto-renew</th>
                    <th data-no-export><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
${tenants
    .slice()
    .sort((a, b) => a.days - b.days)
    .map((t) => {
        const [badge] = renewalBadge(t.end, t.renew);
        return `                <tr data-filterable data-search="${esc((t.company + ' ' + t.email + ' ' + t.plan + ' ' + t.status).toLowerCase())}">
                    <td><div class="sa-cell-main"><span class="sa-cell-avatar">${esc(initials(t.company))}</span><span class="sa-cell-text"><strong>${esc(t.company)}</strong><span>${esc(t.email)}</span></span></div></td>
                    <td><span class="sa-badge sa-badge-plan">${esc(t.plan)}</span></td>
                    <td class="num" data-sort-value="${t.price}">${esc(money(t.price))}</td>
                    <td data-sort-value="${t.status}">${statusBadge(t.status)}</td>
                    <td data-sort-value="2026-01-01">${esc(shortDate('2026-01-01'))}</td>
                    <td data-sort-value="${t.end}" data-export-value="${t.end}">${esc(shortDate(t.end))}</td>
                    <td data-sort-value="${t.days}">${badge}</td>
                    <td><label class="sa-switch" title="${t.renew ? 'Auto-renew on' : 'Auto-renew off'}"><input type="checkbox" name="auto_renew" value="1" ${t.renew ? 'checked' : ''} disabled><span class="sa-switch-track"></span><span class="sa-sr-only">Auto-renew ${esc(t.company)}</span></label></td>
                    <td data-no-export><div class="sa-row-actions"><button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" title="Extend 12 months (demo)">${icon('refresh')} +12 mo</button><a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenant_details.php?id=${t.id}" title="Open tenant">${icon('eye')}</a></div></td>
                </tr>`;
    })
    .join('\n')}
            </tbody>
        </table>
    </div>
    <div class="sa-empty" id="subsTableEmpty" hidden>${icon('search')}<strong>No matching subscriptions</strong><p>Nothing in this view matches the text in the top-right filter box.</p></div>
    <div class="sa-card-foot"><span>${tenants.length} subscriptions shown</span><span>Toggles and status selects save immediately</span></div>
</section>
`;

/* ------------------------------------------------------------
   PAGE: plans
   ------------------------------------------------------------ */
const topPlan = plans.slice().sort((a, b) => b.tenants - a.tenants)[0];
const plansBody = `
<div class="sa-plans sa-anim">
${plans.map((p) => {
    const featured = p.id === topPlan.id;
    return `    <article class="sa-card sa-plan${featured ? ' is-featured' : ''}">
        <div class="sa-plan-head">
            <div>
                <div class="sa-plan-name">${esc(p.name)}</div>
                <div class="sa-plan-tag">${p.tenants + p.trials} tenants &middot; ${esc(money(p.mrr))} MRR</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
                <span class="sa-badge sa-badge-active">On sale</span>
                ${featured ? '<span class="sa-badge sa-badge-lime">Most popular</span>' : ''}
            </div>
        </div>
        <div class="sa-plan-price"><strong>${esc(money(p.price))}</strong><span>/ month</span></div>
        <div class="sa-plan-stats">
            <div class="sa-plan-stat"><strong>${p.ratings >= 9999 ? '∞' : num(p.ratings)}</strong><span>Ratings / mo</span></div>
            <div class="sa-plan-stat"><strong>${p.customers >= 999 ? '∞' : num(p.customers)}</strong><span>Companies</span></div>
            <div class="sa-plan-stat"><strong>${p.trials}</strong><span>On trial</span></div>
        </div>
        <div>
            <div class="sa-section-title" style="margin:0 0 11px">Included</div>
            <ul class="sa-features">
${p.features.map((f) => `                <li>${icon('check')} ${esc(f)}</li>`).join('\n')}
            </ul>
        </div>
        <div class="sa-plan-foot">
            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" style="flex:1" data-sa-edit-plan data-id="${p.id}" data-name="${esc(p.name)}" data-price="${p.price}" data-ratings="${p.ratings}" data-customers="${p.customers}" data-status="${p.status}" data-features="${esc(p.features.join('\n'))}">${icon('edit')} Edit</button>
            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" title="Hide from new tenants">${icon('eye')} Deactivate</button>
        </div>
    </article>`;
}).join('\n')}
</div>

<dialog class="sa-dialog" id="planCreateDialog">
    <form method="POST" action="plans.php" class="sa-form" onsubmit="return false">
        <div class="sa-dialog-head">
            <div><h3 id="pf_title">Create a plan</h3><p id="pf_subtitle">Prices are monthly. Limits are enforced per tenant.</p></div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close">${icon('x')}</button>
        </div>
        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field"><label for="pf_name">Plan name *</label><input id="pf_name" type="text" name="plan_name" placeholder="e.g. Growth" required></div>
                <div class="sa-field"><label for="pf_price">Price / month *</label><input id="pf_price" type="number" step="0.01" min="0" name="price" placeholder="49.00" required></div>
                <div class="sa-field"><label for="pf_ratings">Ratings per month</label><input id="pf_ratings" type="number" min="0" name="max_ratings" value="100"><span class="sa-hint">Use 9999 for unlimited.</span></div>
                <div class="sa-field"><label for="pf_customers">Company limit</label><input id="pf_customers" type="number" min="0" name="max_customers" value="10"><span class="sa-hint">Use 999 for unlimited.</span></div>
                <div class="sa-field"><label for="pf_status">Availability</label><select id="pf_status" name="status"><option value="active">On sale</option><option value="inactive">Hidden from new tenants</option></select></div>
                <div class="sa-field" style="grid-column:1/-1"><label for="pf_features">Features</label><textarea id="pf_features" name="features" placeholder="One feature per line"></textarea><span class="sa-hint">Commas also work as separators.</span></div>
            </div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary" id="pf_submit">${icon('check')} Create plan</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    function open(sel) {
        var d = document.querySelector(sel);
        if (!d) return;
        if (typeof d.showModal === 'function') d.showModal();
        else { d.setAttribute('open', ''); d.classList.add('is-open-fallback'); }
    }
    document.querySelectorAll('[data-sa-edit-plan]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.dataset;
            document.getElementById('pf_name').value = d.name;
            document.getElementById('pf_price').value = d.price;
            document.getElementById('pf_ratings').value = d.ratings;
            document.getElementById('pf_customers').value = d.customers;
            document.getElementById('pf_status').value = d.status;
            document.getElementById('pf_features').value = d.features;
            document.getElementById('pf_title').textContent = 'Edit plan';
            document.getElementById('pf_subtitle').textContent = 'Changes apply to every tenant on this plan.';
            document.getElementById('pf_submit').textContent = 'Save plan';
            open('#planCreateDialog');
        });
    });
    document.querySelectorAll('[data-sa-open-dialog="#planCreateDialog"]').forEach(function (opener) {
        opener.addEventListener('click', function () {
            document.getElementById('pf_title').textContent = 'Create a plan';
            document.getElementById('pf_submit').textContent = 'Create plan';
        });
    });
})();
</script>
`;

/* ------------------------------------------------------------
   PAGE: analytics
   ------------------------------------------------------------ */
const analyticsBody = `
<div class="sa-grid sa-kpis sa-anim">
${kpi({ label: 'MRR growth', icon: 'trending-up', value: delta(((mrr - 210) / 210) * 100), note: `${esc(money(210))} → ${esc(money(mrr))}`, accent: '--sa-lime', soft: '--sa-accent-soft', line: '--sa-accent-line' })}
${kpi({ label: 'Tenant growth', icon: 'building', value: delta(((tenants.length - 3) / 3) * 100), note: `3 → ${tenants.length} tenants`, accent: '--sa-info', soft: '--sa-info-soft', line: '--sa-info-line' })}
${kpi({ label: 'Ratings in window', icon: 'star', value: num(totalRatings), note: '', accent: '--sa-warning', soft: '--sa-warning-soft', line: '--sa-warning-line' })}
${kpi({ label: 'Revenue added', icon: 'dollar', value: esc(money(1210, 0)), note: 'New MRR signed in the window', accent: '--sa-violet', soft: '--sa-violet-soft', line: '--sa-violet-line' })}
</div>

<div class="sa-grid sa-split-2-1">
    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Revenue trend</h3><p>Cumulative monthly recurring revenue</p></div><span class="sa-pill">${esc(money(mrr))} today</span></div>
        <div class="sa-card-pad">${lineChart(labels, [{ name: 'MRR', values: mrrSeries, format: 'money' }], { height: 280, format: 'money' })}</div>
    </section>
    <section class="sa-card">
        <div class="sa-card-head"><div><h3>New tenants</h3><p>Signups per month</p></div></div>
        <div class="sa-card-pad">${barChart(trend.map((t) => ({ label: t.label, value: t.new })), { height: 280 })}</div>
    </section>
</div>

<div class="sa-grid sa-split-2-1 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Ratings volume</h3><p>Reviews collected per month with the average score</p></div>
            <div class="sa-card-head-actions">
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-lime);display:inline-block"></i> Volume</span>
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-info);display:inline-block"></i> Avg score</span>
            </div>
        </div>
        <div class="sa-card-pad">${lineChart(labels, [{ name: 'Ratings', values: ratingSeries, format: 'number' }, { name: 'Average', values: avgSeries, format: 'decimal', dashed: true }], { height: 250 })}</div>
        <div class="sa-card-foot"><span>${num(totalRatings)} ratings in the last 12 months</span><span>104 in the last 30 days</span></div>
    </section>
    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Score distribution</h3><p>All ratings ever recorded</p></div></div>
        <div class="sa-card-pad">${barList(starItems)}</div>
        <div class="sa-card-foot"><span>${starsRow(avgRating)}</span><span>${Math.round((stars[5] / totalRatings) * 100)}% five-star</span></div>
    </section>
</div>

<div class="sa-grid sa-cols-3 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Plan mix</h3><p>Tenants and MRR per plan</p></div></div>
        <div class="sa-card-pad">${donut(planSegments, { value: num(statusCounts.active + statusCounts.trial), label: 'Subscribed' }, 150)}</div>
    </section>
    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Lifecycle</h3><p>Status of every tenant</p></div></div>
        <div class="sa-card-pad">${donut(statusSegments, { value: num(tenants.length), label: 'Tenants' }, 150)}</div>
    </section>
    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Top companies</h3><p>Most reviewed across all tenants</p></div></div>
        <div class="sa-card-pad">${barList(topBars)}</div>
    </section>
</div>

<section class="sa-card sa-mt">
    <div class="sa-card-head"><div><h3>Rating activity</h3><p>Each square is one day — darker means more ratings collected</p></div><span class="sa-pill">Last ${heatCells.length} days</span></div>
    <div class="sa-card-pad">${heatmap(heatCells, 21)}</div>
</section>

<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Tenant league table</h3><p>Engagement per tenant, best first</p></div>
        <div class="sa-card-head-actions"><button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" data-sa-export="#leagueTable" data-sa-export-name="optibiz-tenant-league">${icon('download')} CSV</button></div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="leagueTable" data-sa-sortable-table>
            <thead><tr><th data-sa-sort="0">Tenant</th><th data-sa-sort="1">Plan</th><th data-sa-sort="2">Status</th><th data-sa-sort="3" data-type="num">Companies</th><th data-sa-sort="4" data-type="num">Ratings</th><th data-sa-sort="5" data-type="num">Avg score</th><th data-sa-sort="6" data-type="num">Share</th></tr></thead>
            <tbody>
${tenants
    .map((t) => ({ ...t, ratings: Math.round(t.companies * 4.6) }))
    .sort((a, b) => b.ratings - a.ratings)
    .map((t) => {
        const avg = 4 + (t.id % 9) / 10;
        const share = Number(pct(t.ratings, totalRatings + 200, 1));
        return `                <tr>
                    <td><div class="sa-cell-main"><span class="sa-cell-avatar">${esc(initials(t.company))}</span><span class="sa-cell-text"><strong>${esc(t.company)}</strong></span></div></td>
                    <td><span class="sa-badge sa-badge-plan">${esc(t.plan)}</span></td>
                    <td>${statusBadge(t.status)}</td>
                    <td class="num">${num(t.companies)}</td>
                    <td class="num">${num(t.ratings)}</td>
                    <td>${starsRow(avg)}</td>
                    <td style="min-width:130px" data-sort-value="${share}"><div class="sa-progress"><i style="--w:${share}%"></i></div></td>
                </tr>`;
    })
    .join('\n')}
            </tbody>
        </table>
    </div>
</section>

<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Monthly breakdown</h3><p>The numbers behind the charts above</p></div>
        <div class="sa-card-head-actions"><button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" data-sa-export="#monthlyTable" data-sa-export-name="optibiz-monthly-breakdown">${icon('download')} CSV</button></div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="monthlyTable" data-sa-sortable-table>
            <thead><tr><th data-sa-sort="0" data-type="date">Month</th><th data-sa-sort="1" data-type="num">New tenants</th><th data-sa-sort="2" data-type="num">Total tenants</th><th data-sa-sort="3" data-type="num">New MRR</th><th data-sa-sort="4" data-type="num">Total MRR</th><th data-sa-sort="5" data-type="num">Ratings</th><th data-sa-sort="6" data-type="num">Avg score</th></tr></thead>
            <tbody>
${trend
    .slice()
    .reverse()
    .map((t, i) => `                <tr>
                    <td>${esc(t.label + ' ' + (2026 - Math.floor(i / 12)))}</td>
                    <td class="num">${t.new}</td>
                    <td class="num">${t.tenants}</td>
                    <td class="num" data-export-value="${(t.mrr - (trend[trend.length - 2 - i] || { mrr: 0 }).mrr).toFixed(2)}">${esc(money(Math.max(0, t.mrr - (trend[trend.length - 2 - i] || { mrr: 0 }).mrr)))}</td>
                    <td class="num" data-export-value="${t.mrr.toFixed(2)}">${esc(money(t.mrr))}</td>
                    <td class="num">${t.ratings}</td>
                    <td class="num">${t.avg.toFixed(2)} ★</td>
                </tr>`)
    .join('\n')}
            </tbody>
        </table>
    </div>
</section>
`;

/* ------------------------------------------------------------
   PAGE: quote requests
   ------------------------------------------------------------ */
const quoteChips = [
    ['all', 'All requests', quotes.length],
    ['pending', 'Pending', quotes.filter((q) => q.status === 'pending').length],
    ['contacted', 'Contacted', quotes.filter((q) => q.status === 'contacted').length],
    ['converted', 'Converted', quotes.filter((q) => q.status === 'converted').length],
    ['rejected', 'Rejected', quotes.filter((q) => q.status === 'rejected').length],
];
const selectedQuote = quotes[0];

const quotesBody = `
<div class="sa-grid sa-kpis sa-anim">
${kpi({ label: 'Awaiting reply', icon: 'inbox', value: num(quoteChips[1][2]), note: 'New leads that nobody has contacted yet', accent: '--sa-warning', soft: '--sa-warning-soft', line: '--sa-warning-line' })}
${kpi({ label: 'In conversation', icon: 'message', value: num(quoteChips[2][2]), note: 'Contacted, not closed yet', accent: '--sa-info', soft: '--sa-info-soft', line: '--sa-info-line' })}
${kpi({ label: 'Converted', icon: 'check-circle', value: num(quoteChips[3][2]), note: `${esc(money(199.99))} MRR won from the form`, accent: '--sa-success', soft: '--sa-success-soft', line: '--sa-success-line' })}
${kpi({ label: 'Rejected', icon: 'x', value: num(quoteChips[4][2]), note: 'Not a fit, spam or duplicates', accent: '--sa-danger', soft: '--sa-danger-soft', line: '--sa-danger-line' })}
</div>

<section class="sa-card sa-mb">
    <div class="sa-filters">
        <div class="sa-chips">
${quoteChips.map(([key, label, count], i) => `            <a class="sa-chip${i === 0 ? ' active' : ''}" href="quote_requests.php?status=${key}" aria-pressed="${i === 0}">${esc(label)}<span class="count">${count}</span></a>`).join('\n')}
        </div>
    </div>
</section>

<section class="sa-card">
    <div class="sa-card-head"><div><h3>All requests</h3><p>${quotes.length} results</p></div></div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="quotesTable" data-sa-sortable-table>
            <thead><tr><th data-sa-sort="0">Company</th><th data-sa-sort="1">Contact</th><th data-sa-sort="2">Category</th><th data-sa-sort="3">Interested in</th><th data-sa-sort="4" data-type="num">Volume</th><th data-sa-sort="5">Status</th><th data-sa-sort="6" data-type="date">Received</th><th data-no-export><span class="sa-sr-only">Actions</span></th></tr></thead>
            <tbody>
${quotes.map((q) => `                <tr data-filterable data-search="${esc((q.company + ' ' + q.contact + ' ' + q.email + ' ' + q.location + ' ' + q.status).toLowerCase())}">
                    <td><div class="sa-cell-main"><span class="sa-cell-avatar">${esc(initials(q.company))}</span><span class="sa-cell-text"><strong>${esc(q.company)}</strong><span>${esc(q.location)}</span></span></div></td>
                    <td><span class="sa-cell-text"><strong style="font-weight:500">${esc(q.contact)}</strong><span>${esc(q.email)}</span></span></td>
                    <td><span class="sa-badge sa-badge-info">${esc(q.category)}</span></td>
                    <td><span class="sa-badge sa-badge-plan">${esc(q.plan)}</span></td>
                    <td class="num" data-sort-value="${q.ratings}">${q.companies} cos · ${q.ratings} ratings</td>
                    <td data-sort-value="${q.status}">${statusBadge(q.status)}</td>
                    <td data-sort-value="${q.created}">${esc(shortDate(q.created))}</td>
                    <td data-no-export>
                        <div class="sa-row-actions">
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="quote_requests.php?id=${q.id}" title="View details">${icon('eye')}</a>
${q.status !== 'converted' ? `                            <button type="button" class="sa-btn sa-btn-sm sa-btn-primary" title="Convert to tenant" data-sa-convert data-id="${q.id}" data-company="${esc(q.company)}" data-email="${esc(q.email)}" data-phone="${esc(q.phone)}" data-plan="${q.plan_id}">${icon('zap')} Convert</button>` : ''}
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-danger" title="Delete request (demo)">${icon('trash')}</button>
                        </div>
                    </td>
                </tr>`).join('\n')}
            </tbody>
        </table>
    </div>
    <div class="sa-empty" id="quotesTableEmpty" hidden>${icon('search')}<strong>No matching requests</strong><p>Nothing in the pipeline matches that text.</p></div>
</section>

<div class="sa-grid sa-split-2-1 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>${esc(selectedQuote.company)}</h3><p>Received ${esc(shortDate(selectedQuote.created))} &middot; ${esc(timeAgo(selectedQuote.created))}</p></div>
            <div class="sa-card-head-actions">${statusBadge(selectedQuote.status)}<a class="sa-btn sa-btn-sm sa-btn-ghost" href="quote_requests.php">${icon('x')} Close</a></div>
        </div>
        <div class="sa-card-pad">
            <dl class="sa-kv">
                <div class="sa-kv-row"><dt>Contact person</dt><dd>${esc(selectedQuote.contact)}</dd></div>
                <div class="sa-kv-row"><dt>Email</dt><dd><a href="mailto:${esc(selectedQuote.email)}" style="color:var(--sa-accent);text-decoration:none">${esc(selectedQuote.email)}</a></dd></div>
                <div class="sa-kv-row"><dt>Phone</dt><dd>${esc(selectedQuote.phone || '—')}</dd></div>
                <div class="sa-kv-row"><dt>Website</dt><dd>${esc(selectedQuote.website || '—')}</dd></div>
                <div class="sa-kv-row"><dt>Location</dt><dd>${esc(selectedQuote.location)}</dd></div>
                <div class="sa-kv-row"><dt>Category</dt><dd>${esc(selectedQuote.category)}</dd></div>
                <div class="sa-kv-row"><dt>Plan of interest</dt><dd>${esc(selectedQuote.plan)}</dd></div>
                <div class="sa-kv-row"><dt>Companies to list</dt><dd>${esc(num(selectedQuote.companies))}</dd></div>
                <div class="sa-kv-row"><dt>Expected ratings / month</dt><dd>${esc(num(selectedQuote.ratings))}</dd></div>
            </dl>
            <div class="sa-section-title" style="margin:20px 0 10px">Notes</div>
            <p class="sa-muted" style="font-size:13.4px;line-height:1.65">${esc(selectedQuote.notes || 'No notes were left with this request.')}</p>
        </div>
        <div class="sa-card-foot">
            <span>Pipeline value: ${esc(money(79.99))} / month</span>
            <a href="mailto:${esc(selectedQuote.email)}" class="sa-btn sa-btn-sm sa-btn-ghost">${icon('mail')} Reply by email</a>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Move it forward</h3><p>Convert or change the pipeline stage</p></div></div>
        <div class="sa-card-pad sa-stack" style="gap:12px">
            <button type="button" class="sa-btn sa-btn-primary sa-btn-block" data-sa-convert data-id="${selectedQuote.id}" data-company="${esc(selectedQuote.company)}" data-email="${esc(selectedQuote.email)}" data-phone="${esc(selectedQuote.phone)}" data-plan="${selectedQuote.plan_id}">${icon('zap')} Convert to tenant</button>
            <div class="sa-field"><label for="detail_status">Pipeline stage</label>
                <select id="detail_status" class="sa-select" name="status">${['pending', 'contacted', 'converted', 'rejected'].map((s) => `<option value="${s}"${selectedQuote.status === s ? ' selected' : ''}>${s.charAt(0).toUpperCase() + s.slice(1)}</option>`).join('')}</select>
            </div>
            <button type="button" class="sa-btn sa-btn-ghost sa-btn-block">${icon('save')} Save stage</button>
            <button type="button" class="sa-btn sa-btn-danger sa-btn-block">${icon('trash')} Delete request</button>
        </div>
    </section>
</div>

<dialog class="sa-dialog" id="convertDialog">
    <form method="POST" action="quote_requests.php" class="sa-form" onsubmit="return false">
        <div class="sa-dialog-head">
            <div><h3>Convert to a tenant</h3><p id="cv_subtitle">Creates the tenant login and marks this request converted.</p></div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close">${icon('x')}</button>
        </div>
        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field"><label for="cv_company">Company name *</label><input id="cv_company" type="text" name="company_name" required></div>
                <div class="sa-field"><label for="cv_email">Email *</label><input id="cv_email" type="email" name="email" required></div>
                <div class="sa-field"><label for="cv_phone">Phone</label><input id="cv_phone" type="tel" name="phone"></div>
                <div class="sa-field"><label for="cv_plan">Plan</label><select id="cv_plan" name="plan_id">${plans.map((p) => `<option value="${p.id}">${esc(p.name + ' — ' + money(p.price) + '/mo')}</option>`).join('')}</select></div>
                <div class="sa-field"><label for="cv_status">Starts as</label><select id="cv_status" name="subscription_status"><option value="trial">Trial</option><option value="active">Active (paying)</option></select></div>
                <div class="sa-field"><label for="cv_months">Length (months)</label><input id="cv_months" type="number" name="months" min="0" max="60" value="1"></div>
                <div class="sa-field" style="grid-column:1/-1"><label for="cv_password">Temporary password *</label><input id="cv_password" type="text" name="password" minlength="6" placeholder="At least 6 characters" required></div>
            </div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary">${icon('zap')} Create tenant</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    function open(sel) {
        var d = document.querySelector(sel);
        if (!d) return;
        if (typeof d.showModal === 'function') d.showModal();
        else { d.setAttribute('open', ''); d.classList.add('is-open-fallback'); }
    }
    document.querySelectorAll('[data-sa-convert]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.dataset;
            document.getElementById('cv_company').value = d.company;
            document.getElementById('cv_email').value = d.email;
            document.getElementById('cv_phone').value = d.phone;
            document.getElementById('cv_plan').value = d.plan || '';
            document.getElementById('cv_subtitle').textContent = 'Converting request #' + d.id + ' — ' + d.company;
            open('#convertDialog');
        });
    });
})();
</script>
`;

/* ------------------------------------------------------------
   PAGE: tenant details
   ------------------------------------------------------------ */
const demo = tenants[0];
const [demoBadge, demoKind] = renewalBadge(demo.end, demo.renew);
const lifetime = 8 * demo.price;

const detailsBody = `
<div class="sa-grid sa-kpis sa-anim">
${kpi({ label: 'Monthly revenue', icon: 'dollar', value: esc(money(demo.price)), note: `${esc(money(lifetime, 0))} lifetime value over 8 months`, accent: '--sa-lime', soft: '--sa-accent-soft', line: '--sa-accent-line' })}
${kpi({ label: 'Companies managed', icon: 'users', value: num(demo.companies), note: '999 allowed by the Enterprise plan', accent: '--sa-info', soft: '--sa-info-soft', line: '--sa-info-line' })}
${kpi({ label: 'Ratings collected', icon: 'star', value: num(196), note: '', accent: '--sa-warning', soft: '--sa-warning-soft', line: '--sa-warning-line' })}
${kpi({ label: 'Renewal', icon: 'calendar', value: demo.days + 'd', note: `${esc(shortDate(demo.end))}`, accent: demo.days <= 30 ? '--sa-danger' : '--sa-success', soft: demo.days <= 30 ? '--sa-danger-soft' : '--sa-success-soft', line: demo.days <= 30 ? '--sa-danger-line' : '--sa-success-line' })}
</div>

<div class="sa-grid sa-cols-3">
    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Company information</h3><p>Primary contact for this tenant</p></div></div>
        <div class="sa-card-pad">
            <dl class="sa-kv">
                <div class="sa-kv-row"><dt>Company</dt><dd>${esc(demo.company)}</dd></div>
                <div class="sa-kv-row"><dt>Email</dt><dd><a href="mailto:${esc(demo.email)}" style="color:var(--sa-accent);text-decoration:none">${esc(demo.email)}</a></dd></div>
                <div class="sa-kv-row"><dt>Phone</dt><dd>${esc(demo.phone)}</dd></div>
                <div class="sa-kv-row"><dt>Tenant login</dt><dd class="sa-mono">${esc(demo.username)}</dd></div>
                <div class="sa-kv-row"><dt>Created</dt><dd>${esc(shortDate(demo.created))}</dd></div>
            </dl>
        </div>
        <div class="sa-card-foot"><span>Login at <span class="sa-mono">../admin/login.php</span></span><a href="tenants.php">Manage all tenants ${icon('chevron-right')}</a></div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Subscription</h3><p>Plan, billing dates and renewal</p></div></div>
        <div class="sa-card-pad">
            <dl class="sa-kv">
                <div class="sa-kv-row"><dt>Plan</dt><dd><span class="sa-badge sa-badge-plan">${esc(demo.plan)}</span></dd></div>
                <div class="sa-kv-row"><dt>Price</dt><dd>${esc(money(demo.price))} / month</dd></div>
                <div class="sa-kv-row"><dt>Status</dt><dd>${statusBadge(demo.status)}</dd></div>
                <div class="sa-kv-row"><dt>Started</dt><dd>${esc(shortDate('2026-08-24'))}</dd></div>
                <div class="sa-kv-row"><dt>Ends</dt><dd>${esc(shortDate(demo.end))}</dd></div>
            </dl>
            <label class="sa-switch sa-mt"><input type="checkbox" name="auto_renew" value="1" ${demo.renew ? 'checked' : ''} disabled><span class="sa-switch-track"></span><span class="sa-switch-text">Auto-renew this subscription</span></label>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head"><div><h3>Plan usage</h3><p>How much of the allowance is used</p></div></div>
        <div class="sa-card-pad">
            ${barList([
                { label: 'Companies', value: demo.companies, meta: `${demo.companies} of ∞`, color: 'linear-gradient(90deg,#a8e030,#c2f542)' },
                { label: 'Ratings this month', value: 42, meta: '42 of ∞', color: 'linear-gradient(90deg,#38bdf8,#7dd3fc)' },
            ])}
            <div class="sa-section-title" style="margin:22px 0 11px">Score breakdown</div>
            ${barList(starItems.slice(0, 5))}
        </div>
        <div class="sa-card-foot"><span>${Math.round((stars[5] / totalRatings) * 100)}% five-star</span><a href="plans.php">Change plan ${icon('chevron-right')}</a></div>
    </section>
</div>

<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Companies managed by this tenant</h3><p>${demo.companies} entries in the directory</p></div>
        <div class="sa-card-head-actions"><span class="sa-pill">196 ratings total</span></div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="companiesTable" data-sa-sortable-table>
            <thead><tr><th data-sa-sort="0">Company</th><th data-sa-sort="1">Category</th><th data-sa-sort="2">Website</th><th data-sa-sort="3" data-type="num">Ratings</th><th data-sa-sort="4" data-type="num">Average</th><th data-sa-sort="5" data-type="date">Added</th></tr></thead>
            <tbody>
${[
    ['Volta Haulage Division', 'Manufacturing', 'volta-haulage.gh', 64, 4.8, '2026-08-25'],
    ['Volta Cold Storage', 'Retail', 'voltacold.gh', 41, 4.6, '2026-08-26'],
    ['Volta Freight Forwarding', 'Technology', 'voltafreight.com', 38, 4.7, '2026-08-27'],
    ['Volta Warehouse Tema', 'Manufacturing', '', 29, 4.4, '2026-08-28'],
    ['Volta Last-Mile Accra', 'Retail', 'voltalastmile.gh', 24, 4.5, '2026-08-29'],
].map(([name, cat, site, r, avg, added]) => `                <tr>
                    <td><div class="sa-cell-main"><span class="sa-cell-avatar">${esc(initials(name))}</span><span class="sa-cell-text"><strong>${esc(name)}</strong><span>ops@${esc(name.toLowerCase().replace(/[^a-z]/g, '').slice(0, 12))}.gh</span></span></div></td>
                    <td><span class="sa-badge sa-badge-info">${esc(cat)}</span></td>
                    <td>${site ? `<a href="https://${esc(site)}" target="_blank" rel="noopener" style="color:var(--sa-accent);text-decoration:none">${esc(site)}</a>` : '<span class="sa-faint">—</span>'}</td>
                    <td class="num">${r}</td>
                    <td>${starsRow(avg)}</td>
                    <td data-sort-value="${added}">${esc(shortDate(added))}</td>
                </tr>`).join('\n')}
            </tbody>
        </table>
    </div>
</section>

<section class="sa-card sa-mt">
    <div class="sa-card-head"><div><h3>Latest ratings</h3><p>Most recent feedback collected for this tenant</p></div></div>
    <div class="sa-list">
${[
    ['Volta Haulage Division', 'Abena Owusu', 'Drivers were on time and the cargo tracking page is excellent.', 5, '3 hours ago'],
    ['Volta Cold Storage', 'Kojo Antwi', 'Good service, but the invoice arrived two days late.', 4, '9 hours ago'],
    ['Volta Freight Forwarding', 'Nii Armah', 'Smooth customs clearance, will use them again.', 5, 'yesterday'],
    ['Volta Warehouse Tema', 'Grace Mensah', 'Pallets were mislabelled on arrival.', 3, '2 days ago'],
].map(([company, who, comment, r, when]) => `        <div class="sa-list-item" style="align-items:flex-start">
            <span class="sa-list-icon is-warning">${icon('star')}</span>
            <span class="sa-list-body"><strong>${esc(company)} &middot; ${esc(who)}</strong><span>${esc(comment)}</span></span>
            <span class="sa-list-side">${starsRow(r, false)}<strong style="margin-top:4px">${esc(when)}</strong></span>
        </div>`).join('\n')}
    </div>
</section>
`;

/* ------------------------------------------------------------
   PAGE: settings
   ------------------------------------------------------------ */
const settingFields = [
    ['site_name', 'Platform name', 'text', 'Optibiz', 'Shown in emails, the header and the footer.'],
    ['admin_email', 'Admin email', 'email', 'admin@optibiz.com', 'Where system notifications are sent.'],
    ['support_email', 'Support email', 'email', 'support@optibiz.com', 'Published to tenants as the support contact.'],
    ['currency_symbol', 'Currency symbol', 'text', '$', 'Used everywhere money is shown ($, €, £, GH₵…).'],
    ['ratings_per_page', 'Ratings per page', 'number', '10', 'Pagination size on the public and tenant views.'],
    ['trial_days', 'Default trial length (days)', 'number', '30', 'Suggested length when creating a tenant.'],
];
const healthRows = [
    ['super_admins', true, 1, 'This control center'],
    ['subscription_plans', true, 3, 'Plans & pricing'],
    ['tenants', true, 18, 'Tenants, subscriptions, revenue'],
    ['admins', true, 1, 'Tenant admin logins'],
    ['categories', true, 5, 'Company categories'],
    ['customers', true, 184, 'Companies listed by tenants'],
    ['ratings', true, 660, 'Ratings, analytics, engagement'],
    ['settings', true, 6, 'This page'],
    ['quote_requests', true, 6, 'Sales pipeline'],
];

const settingsBody = `
<div class="sa-grid sa-split-2-1">
    <section class="sa-card">
        <form method="POST" action="settings.php" class="sa-form" onsubmit="return false">
            <div class="sa-card-head"><div><h3>Platform defaults</h3><p>Branding and the numbers the app runs on</p></div></div>
            <div class="sa-card-pad">
                <div class="sa-form-grid">
${settingFields.map(([key, label, type, value, hint]) => `                    <div class="sa-field">
                        <label for="s_${key}">${esc(label)}</label>
                        <input id="s_${key}" type="${type}" name="${key}" value="${esc(value)}" ${type === 'number' ? 'min="0" step="1"' : ''}>
                        <span class="sa-hint">${esc(hint)}</span>
                    </div>`).join('\n')}
                </div>
                <div class="sa-section-title" style="margin:24px 0 12px">Appearance</div>
                <div class="sa-flex" style="gap:14px;flex-wrap:wrap">
                    <button type="button" class="sa-btn sa-btn-ghost" data-sa-theme>${icon('sun')} Switch to light theme</button>
                    <span class="sa-muted" style="font-size:12.6px">Your choice is remembered in this browser only.</span>
                </div>
            </div>
            <div class="sa-card-foot"><span>Stored in the <span class="sa-mono">settings</span> table</span><button type="submit" class="sa-btn sa-btn-primary">${icon('save')} Save settings</button></div>
        </form>
    </section>

    <div class="sa-stack">
        <section class="sa-card">
            <form method="POST" action="settings.php" class="sa-form" onsubmit="return false">
                <div class="sa-card-head">
                    <div><h3>Your account</h3><p>Signed in as ${esc(ADMIN)}</p></div>
                    <span class="sa-user-avatar" style="width:38px;height:38px;border-radius:12px">${esc(initials(ADMIN))}</span>
                </div>
                <div class="sa-card-pad sa-form" style="gap:14px">
                    <div class="sa-field"><label for="a_username">Username</label><input id="a_username" type="text" name="username" value="${esc(ADMIN)}" required></div>
                    <div class="sa-field"><label for="a_email">Email</label><input id="a_email" type="email" name="email" value="superadmin@optibiz.com"></div>
                    <div class="sa-field"><label>Member since</label><div class="sa-muted" style="font-size:13px">Jan 01, 2026</div></div>
                </div>
                <div class="sa-card-foot"><span>Used to sign in at <span class="sa-mono">/superadmin/login.php</span></span><button type="submit" class="sa-btn sa-btn-ghost">${icon('save')} Save</button></div>
            </form>
        </section>

        <section class="sa-card">
            <form method="POST" action="settings.php" class="sa-form" onsubmit="return false">
                <div class="sa-card-head">
                    <div><h3>Change password</h3><p>Minimum 8 characters</p></div>
                    <span class="sa-kpi-icon" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">${icon('key')}</span>
                </div>
                <div class="sa-card-pad sa-form" style="gap:14px">
                    <div class="sa-field"><label for="p_current">Current password</label><input id="p_current" type="password" name="current_password" autocomplete="current-password" required></div>
                    <div class="sa-field"><label for="p_new">New password</label><input id="p_new" type="password" name="new_password" minlength="8" autocomplete="new-password" required></div>
                    <div class="sa-field"><label for="p_confirm">Confirm new password</label><input id="p_confirm" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
                </div>
                <div class="sa-card-foot"><span>Hashed with PHP's bcrypt</span><button type="submit" class="sa-btn sa-btn-primary">${icon('shield')} Update password</button></div>
            </form>
        </section>
    </div>
</div>

<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Database health</h3><p>Tables found in <span class="sa-mono">company_rating_saas</span></p></div>
        <div class="sa-card-head-actions">
            <span class="sa-pill">${healthRows.length} / ${healthRows.length} present</span>
            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" onclick="window.location.reload()">${icon('refresh')} Re-check</button>
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" data-sa-sortable-table>
            <thead><tr><th>Table</th><th>Status</th><th data-sa-sort="2" data-type="num">Rows</th><th>Where it is used</th></tr></thead>
            <tbody>
${healthRows.map(([table, exists, rows, usage]) => `                <tr>
                    <td class="sa-mono">${esc(table)}</td>
                    <td>${exists ? '<span class="sa-badge sa-badge-active">Present</span>' : '<span class="sa-badge sa-badge-inactive">Missing</span>'}</td>
                    <td class="num">${num(rows)}</td>
                    <td class="sa-muted">${esc(usage)}</td>
                </tr>`).join('\n')}
            </tbody>
        </table>
    </div>
    <div class="sa-card-foot"><span>PHP 8.2.7 &middot; server time ${esc(new Date().toLocaleString('en-GB'))}</span><span>Schema lives in <span class="sa-mono">database.sql</span></span></div>
</section>
`;

/* ------------------------------------------------------------
   Build pages
   ------------------------------------------------------------ */
const exportBtn = (target, name, label = 'Export CSV') =>
    `<button type="button" class="sa-btn sa-btn-ghost" data-sa-export="${target}" data-sa-export-name="${name}">${icon('download')} ${label}</button>`;

const PAGES = [
    {
        file: 'index.html',
        title: 'Control Center',
        heading: 'Control center',
        subtitle: 'Platform health, revenue and tenant activity at a glance.',
        h2: 'Welcome back, superadmin',
        p: `${num(tenants.length)} tenants on the platform, ${statusCounts.active} paying and ${esc(money(mrr))} in monthly recurring revenue.`,
        crumbs: ['Super admin', 'Dashboard'],
        active: 'dashboard',
        searchTarget: '#tenantsTable',
        searchPlaceholder: 'Filter recent tenants…',
        actions: `${exportBtn('#tenantsTable', 'optibiz-recent-tenants', 'Export')}
        <a class="sa-btn sa-btn-primary" href="tenants.php">${icon('plus')} Add tenant</a>`,
        body: dashboardBody,
    },
    {
        file: 'tenants.html',
        title: 'Tenants',
        heading: 'Tenants',
        subtitle: 'Every company subscribed to the Optibiz platform.',
        h2: 'Manage tenants',
        p: `${tenants.length} companies &middot; ${statusCounts.active} active &middot; ${statusCounts.trial} on trial &middot; ${esc(money(mrr))} MRR`,
        crumbs: ['Super admin', 'Tenants'],
        active: 'tenants',
        searchTarget: '#tenantsTable',
        searchPlaceholder: 'Filter tenants…',
        actions: `${exportBtn('#tenantsTable', 'optibiz-tenants')}
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#tenantCreateDialog">${icon('plus')} New tenant</button>`,
        body: tenantsBody,
    },
    {
        file: 'subscriptions.html',
        title: 'Subscriptions',
        heading: 'Subscriptions',
        subtitle: 'Billing status, renewals and auto-renew for every tenant.',
        h2: 'Subscriptions & billing',
        p: `${esc(money(mrr))} monthly recurring revenue across ${statusCounts.active} paying tenants.`,
        crumbs: ['Super admin', 'Subscriptions'],
        active: 'subscriptions',
        searchTarget: '#subsTable',
        searchPlaceholder: 'Filter subscriptions…',
        actions: `${exportBtn('#subsTable', 'optibiz-subscriptions')}
        <a class="sa-btn sa-btn-primary" href="tenants.php">${icon('plus')} New tenant</a>`,
        body: subsBody,
    },
    {
        file: 'plans.html',
        title: 'Plans',
        heading: 'Plans & pricing',
        subtitle: 'What tenants can buy, and how each plan is performing.',
        h2: 'Subscription plans',
        p: `${plans.length} plans &middot; ${esc(money(mrr))} MRR &middot; most popular: ${esc(topPlan.name)}`,
        crumbs: ['Super admin', 'Plans'],
        active: 'plans',
        actions: `<button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#planCreateDialog">${icon('plus')} New plan</button>`,
        body: plansBody,
    },
    {
        file: 'analytics.html',
        title: 'Analytics',
        heading: 'Analytics',
        subtitle: 'Revenue, growth and engagement across every tenant.',
        h2: 'Platform analytics',
        p: 'Everything below covers the last 12 months.',
        crumbs: ['Super admin', 'Analytics'],
        active: 'analytics',
        actions: `<div class="sa-chips">
            <a class="sa-chip" href="analytics.php?months=6" aria-pressed="false">6 mo</a>
            <a class="sa-chip active" href="analytics.php?months=12" aria-pressed="true">12 mo</a>
            <a class="sa-chip" href="analytics.php?months=24" aria-pressed="false">24 mo</a>
        </div>
        ${exportBtn('#monthlyTable', 'optibiz-monthly-analytics', 'Export')}`,
        body: analyticsBody,
    },
    {
        file: 'quote_requests.html',
        title: 'Quote requests',
        heading: 'Quote requests',
        subtitle: 'Inbound leads from the public website.',
        h2: 'Sales pipeline',
        p: `${quotes.length} requests &middot; ${quotes.filter((q) => q.status === 'pending').length} waiting for a reply &middot; ${Math.round((quotes.filter((q) => q.status === 'converted').length / quotes.length) * 100)}% converted`,
        crumbs: ['Super admin', 'Quote requests'],
        active: 'quotes',
        searchTarget: '#quotesTable',
        searchPlaceholder: 'Filter requests…',
        actions: exportBtn('#quotesTable', 'optibiz-quote-requests'),
        body: quotesBody,
    },
    {
        file: 'tenant_details.html',
        title: demo.company,
        heading: demo.company,
        subtitle: `Tenant #${demo.id} · ${demo.plan}`,
        h2: demo.company,
        p: `Customer since ${esc(shortDate(demo.created))} &middot; ${demo.companies} companies &middot; 196 ratings`,
        crumbs: ['Super admin', 'Tenants', demo.company],
        crumbHref: 'tenants.php',
        active: 'tenants',
        actions: `<a class="sa-btn sa-btn-ghost" href="tenants.php">${icon('arrow-left')} All tenants</a>
        <button type="button" class="sa-btn sa-btn-primary">${icon('refresh')} Extend 12 months</button>`,
        body: detailsBody,
    },
    {
        file: 'settings.html',
        title: 'Settings',
        heading: 'Platform settings',
        subtitle: 'Branding, defaults, your account and database health.',
        h2: 'Settings',
        p: 'Changes apply to the whole platform immediately.',
        crumbs: ['Super admin', 'Settings'],
        active: 'settings',
        actions: '',
        body: settingsBody,
    },
];

function copyDir(src, dest) {
    fs.mkdirSync(dest, { recursive: true });
    for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
        const s = path.join(src, entry.name);
        const d = path.join(dest, entry.name);
        if (entry.isDirectory()) copyDir(s, d);
        else fs.copyFileSync(s, d);
    }
}

function build() {
    fs.rmSync(OUT, { recursive: true, force: true });
    fs.mkdirSync(path.join(OUT, 'superadmin'), { recursive: true });
    copyDir(path.join(ROOT, 'assets'), path.join(OUT, 'assets'));

    for (const p of PAGES) {
        const html = page(p);
        fs.writeFileSync(path.join(OUT, 'superadmin', p.file), html, 'utf8');
        console.log('  wrote .preview/superadmin/' + p.file);
    }

    // Login page (static copy of the real auth design)
    fs.writeFileSync(path.join(OUT, 'login.html'), loginPage(), 'utf8');
    console.log('  wrote .preview/login.html');

    fs.writeFileSync(path.join(OUT, 'index.html'), previewIndex(), 'utf8');
    console.log('  wrote .preview/index.html');
}

function loginPage() {
    return `<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login &middot; Optibiz</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
<div class="auth-shell">
    <aside class="auth-brand">
        <a class="auth-logo" href="index.html">
            <span class="auth-logo-badge"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
            <span class="auth-logo-name"><strong>Optibiz</strong><span>Rating Platform</span></span>
        </a>
        <div class="auth-brand-body">
            <h1>Every tenant, every plan &mdash; <em>one command center</em>.</h1>
            <p>Sign in to manage tenants, subscriptions and the settings that power the whole platform.</p>
            <ul class="auth-points">
                <li><span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Manage tenants &amp; subscriptions</li>
                <li><span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Track platform-wide analytics</li>
                <li><span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Configure plans &amp; pricing</li>
            </ul>
        </div>
        <p class="auth-brand-foot">&copy; ${new Date().getFullYear()} Optibiz &middot; Company Rating Platform</p>
    </aside>

    <main class="auth-panel">
        <section class="auth-card" aria-labelledby="authCardTitle">
            <header class="auth-card-head">
                <span class="auth-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg></span>
                <h2 id="authCardTitle">Super admin sign in</h2>
                <p>Enter your platform credentials to continue.</p>
            </header>

            <form method="POST" class="auth-form" id="authForm" novalidate action="superadmin/index.html">
                <div class="auth-field">
                    <label for="username">Username</label>
                    <div class="auth-input-wrap">
                        <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" id="username" name="username" value="superadmin" autocomplete="username" required>
                    </div>
                </div>
                <div class="auth-field">
                    <label for="password">Password</label>
                    <div class="auth-input-wrap auth-has-toggle">
                        <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="auth-toggle" id="pwToggle" aria-label="Show password" aria-pressed="false">
                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="auth-submit" id="authSubmit">
                    <span class="auth-submit-label">Sign in to control center</span>
                    <span class="auth-spinner" aria-hidden="true"></span>
                </button>
            </form>

            <footer class="auth-card-foot">
                <a href="index.html"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back to website</a>
                <span class="auth-secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Restricted access</span>
            </footer>
        </section>
    </main>
</div>
<script src="assets/js/auth.js"></script>
</body>
</html>
`;
}

function previewIndex() {
    const cards = PAGES.map(
        (p) => `<a class="pv-card" href="superadmin/${p.file.replace('.html', '.php')}">
            <span class="pv-dot"></span>
            <strong>${esc(p.title)}</strong>
            <span>${esc(p.subtitle)}</span>
        </a>`
    ).join('\n');

    return `<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optibiz Super Admin — preview</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/superadmin.css">
    <style>
        .pv-wrap { max-width: 1080px; margin: 0 auto; padding: clamp(28px, 6vw, 72px) 20px 80px; }
        .pv-hero { display: flex; align-items: center; gap: 14px; margin-bottom: 10px; }
        .pv-hero .sa-brand-badge { width: 46px; height: 46px; }
        .pv-hero h1 { font-size: clamp(24px, 4vw, 34px); }
        .pv-lede { color: var(--sa-muted); max-width: 68ch; margin: 10px 0 26px; font-size: 14.5px; line-height: 1.7; }
        .pv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .pv-card { display: grid; gap: 5px; padding: 18px; border-radius: 16px; border: 1px solid var(--sa-line);
            background: var(--sa-surface); text-decoration: none; color: var(--sa-text);
            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease; }
        .pv-card:hover { transform: translateY(-3px); border-color: var(--sa-accent-line); box-shadow: var(--sa-shadow); }
        .pv-card strong { color: var(--sa-heading); font-size: 15px; }
        .pv-card > span:last-child { color: var(--sa-muted); font-size: 12.6px; line-height: 1.5; }
        .pv-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--sa-lime); box-shadow: 0 0 0 4px var(--sa-accent-soft); }
        .pv-note { margin-top: 30px; padding: 16px 18px; border-radius: 14px; border: 1px dashed var(--sa-line-strong);
            color: var(--sa-muted); font-size: 13px; line-height: 1.7; }
        .pv-toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 26px; }
    </style>
</head>
<body class="sa-body">
<script>
(function () {
    try {
        var t = localStorage.getItem('optibiz-sa-theme');
        if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}
})();
</script>
<div class="pv-wrap">
    <div class="pv-hero">
        <span class="sa-brand-badge"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
        <h1>Super admin — design preview</h1>
    </div>
    <p class="pv-lede">
        This is a <strong>static preview</strong> of the redesigned super admin area, rendered with sample data so the
        layout, colours and interactions can be reviewed without a PHP/MySQL server. The shipped code lives in
        <code>superadmin/*.php</code> and reads real data from the <code>company_rating_saas</code> database.
    </p>
    <div class="pv-toolbar">
        <button type="button" class="sa-btn sa-btn-primary" data-sa-theme>Toggle dark / light</button>
        <a class="sa-btn sa-btn-ghost" href="login.php">Login screen</a>
        <a class="sa-btn sa-btn-ghost" href="superadmin/index.php">Open the dashboard</a>
    </div>
    <div class="pv-grid">
${cards}
    </div>
    <p class="pv-note">
        Theme choice, sidebar collapse, table filtering, column sorting, CSV export and the dialogs all work here —
        they run from the same <code>assets/css/superadmin.css</code> and <code>assets/js/superadmin.js</code>
        the PHP pages load. Buttons that would write to the database are inert in the preview.
    </p>
</div>
<script src="assets/js/superadmin.js"></script>
</body>
</html>
`;
}

build();
console.log('\nPreview built in .preview/ — run tools/preview-server.js to view it.');
