/* ============================================================
   Optibiz Super Admin — shell behaviour
   ------------------------------------------------------------
   * theme toggle (dark / light) persisted in localStorage,
     first visit follows the OS preference
   * sidebar collapse (desktop) + drawer (mobile)
   * avatar dropdown
   * live table filtering  [data-sa-search="#tableId"]
   * click-to-sort columns [data-sa-sortable-table] on the <table>,
   *                       [data-sa-sort="n"] on each sortable <th>
   * count-up animation    [data-sa-count="1234.5"]
   * CSV export            [data-sa-export="#tableId"]
   * flash alert auto-dismiss
   * keyboard: "/" focuses search, Esc closes overlays

   Loaded at the end of <body> on every superadmin page.
   ============================================================ */
(function () {
    'use strict';

    var THEME_KEY = 'optibiz-sa-theme';
    var RAIL_KEY = 'optibiz-sa-rail';

    function onReady(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    /* ---------- Theme ---------- */
    function currentTheme() {
        var root = document.documentElement;
        return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

    function syncThemeButtons(theme) {
        var nodes = document.querySelectorAll('[data-sa-theme]');
        for (var i = 0; i < nodes.length; i++) {
            var btn = nodes[i];
            var next = theme === 'light' ? 'dark' : 'light';
            btn.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
            btn.setAttribute(
                'aria-label',
                'Switch to ' + next + ' theme'
            );
            btn.setAttribute(
                'title',
                'Switch to ' + next + ' theme'
            );
        }
    }

    function setTheme(theme, persist) {
        document.documentElement.setAttribute('data-theme', theme);
        if (persist !== false) {
            try {
                localStorage.setItem(THEME_KEY, theme);
            } catch (e) {
                /* private mode / disabled storage — ignore */
            }
        }
        syncThemeButtons(theme);
    }

    function initTheme() {
        var stored = null;
        try {
            stored = localStorage.getItem(THEME_KEY);
        } catch (e) {
            stored = null;
        }
        var theme =
            stored === 'light' || stored === 'dark'
                ? stored
                : window.matchMedia &&
                  window.matchMedia('(prefers-color-scheme: light)').matches
                ? 'light'
                : 'dark';

        setTheme(theme, false);

        var buttons = document.querySelectorAll('[data-sa-theme]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function () {
                setTheme(currentTheme() === 'light' ? 'dark' : 'light');
            });
        }

        // Follow the OS while the user has not made an explicit choice
        if (!stored && window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: light)');
            var handler = function (e) {
                setTheme(e.matches ? 'light' : 'dark', false);
            };
            if (mq.addEventListener) {
                mq.addEventListener('change', handler);
            } else if (mq.addListener) {
                mq.addListener(handler);
            }
        }

        // Remove the "no transition" guard used to stop the first-paint flash
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                document.documentElement.classList.remove('sa-preload');
            });
        });
    }

    /* ---------- Sidebar: collapse + mobile drawer ---------- */
    function initSidebar() {
        var app = document.querySelector('.sa-app');
        if (!app) {
            return;
        }

        var collapse = document.querySelector('[data-sa-collapse]');
        if (collapse) {
            var stored = null;
            try {
                stored = localStorage.getItem(RAIL_KEY);
            } catch (e) {
                stored = null;
            }
            if (stored === '1') {
                app.classList.add('is-collapsed');
            }
            collapse.addEventListener('click', function () {
                var collapsed = app.classList.toggle('is-collapsed');
                try {
                    localStorage.setItem(RAIL_KEY, collapsed ? '1' : '0');
                } catch (e) {
                    /* ignore */
                }
            });
        }

        var burger = document.querySelector('[data-sa-burger]');
        var scrim = document.querySelector('[data-sa-scrim]');

        function closeDrawer() {
            app.classList.remove('is-mobile-open');
            if (burger) {
                burger.setAttribute('aria-expanded', 'false');
            }
        }

        if (burger) {
            burger.addEventListener('click', function () {
                var open = app.classList.toggle('is-mobile-open');
                burger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
        if (scrim) {
            scrim.addEventListener('click', closeDrawer);
        }

        // Close the drawer after navigating on small screens
        var links = app.querySelectorAll('.sa-nav a');
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener('click', function () {
                if (window.innerWidth <= 900) {
                    closeDrawer();
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeDrawer();
            }
        });

        // Mark the active nav item from the current path (belt & braces:
        // the PHP shell already sets it server-side)
        var path = window.location.pathname.split('/').pop() || 'index.php';
        for (var j = 0; j < links.length; j++) {
            var href = links[j].getAttribute('href') || '';
            if (href === path && !links[j].classList.contains('active')) {
                links[j].classList.add('active');
            }
        }
    }

    /* ---------- Avatar dropdown ---------- */
    function initMenus() {
        var wraps = document.querySelectorAll('[data-sa-menu]');
        if (!wraps.length) {
            return;
        }

        function closeAll(except) {
            for (var i = 0; i < wraps.length; i++) {
                var wrap = wraps[i];
                if (wrap === except) {
                    continue;
                }
                var panel = wrap.querySelector('.sa-menu');
                var trigger = wrap.querySelector('[data-sa-menu-trigger]');
                if (panel) {
                    panel.classList.remove('is-open');
                }
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                }
            }
        }

        for (var i = 0; i < wraps.length; i++) {
            (function (wrap) {
                var trigger = wrap.querySelector('[data-sa-menu-trigger]');
                var panel = wrap.querySelector('.sa-menu');
                if (!trigger || !panel) {
                    return;
                }
                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var open = panel.classList.toggle('is-open');
                    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
                    closeAll(wrap);
                });
                panel.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            })(wraps[i]);
        }

        document.addEventListener('click', function () {
            closeAll(null);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAll(null);
            }
        });
    }

    /* ---------- Notification dropdown ---------- */
    function initNotifications() {
        var notifWraps = document.querySelectorAll('.sa-notification-wrap');
        if (!notifWraps.length) {
            return;
        }

        function closeAllNotifications(except) {
            for (var i = 0; i < notifWraps.length; i++) {
                if (notifWraps[i] === except) {
                    continue;
                }
                notifWraps[i].classList.remove('is-open');
            }
        }

        for (var i = 0; i < notifWraps.length; i++) {
            (function (wrap) {
                var btn = wrap.querySelector('.sa-notification-btn');
                var panel = wrap.querySelector('.sa-notification-panel');
                if (!btn || !panel) {
                    return;
                }

                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var isOpen = wrap.classList.toggle('is-open');
                    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    closeAllNotifications(wrap);
                });

                panel.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            })(notifWraps[i]);
        }

        document.addEventListener('click', function () {
            closeAllNotifications(null);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAllNotifications(null);
            }
        });
    }

    /* ---------- Live table filtering ---------- */
    function initTableSearch() {
        var inputs = document.querySelectorAll('[data-sa-search]');
        for (var i = 0; i < inputs.length; i++) {
            (function (input) {
                var sel = input.getAttribute('data-sa-search') || '';
                var target = null;
                if (sel && sel !== 'global') {
                    try {
                        target = document.querySelector(sel);
                    } catch (err) {
                        target = null;
                    }
                }
                if (!target) {
                    // Global mode (or a stale selector): bind to the first
                    // table on the page that actually has filterable rows.
                    var tables = document.querySelectorAll('table');
                    for (var t = 0; t < tables.length; t++) {
                        if (tables[t].querySelector('tbody tr[data-filterable], tbody tr[data-search]')) {
                            target = tables[t];
                            break;
                        }
                    }
                }
                if (!target) {
                    return;
                }
                var rows = target.querySelectorAll('tbody tr[data-filterable]');
                if (!rows.length) {
                    rows = target.querySelectorAll('tbody tr:not([data-static])');
                }
                var emptySel = input.getAttribute('data-sa-empty');
                var empty = emptySel ? document.querySelector(emptySel) : null;
                if (!empty && target.id) {
                    // Convention: table #fooTable pairs with #fooTableEmpty
                    empty = document.getElementById(target.id + 'Empty');
                }

                input.addEventListener('input', function () {
                    var q = input.value.trim().toLowerCase();
                    var visible = 0;
                    for (var r = 0; r < rows.length; r++) {
                        var text = (
                            rows[r].getAttribute('data-search') || rows[r].textContent
                        ).toLowerCase();
                        var match = !q || text.indexOf(q) !== -1;
                        rows[r].style.display = match ? '' : 'none';
                        if (match) {
                            visible++;
                        }
                    }
                    if (empty) {
                        empty.hidden = visible !== 0;
                    }
                });
            })(inputs[i]);
        }
    }

    /* ---------- Column sorting ---------- */
    function initTableSort() {
        var tables = document.querySelectorAll('[data-sa-sortable-table]');
        for (var t = 0; t < tables.length; t++) {
            (function (table) {
                var headers = table.querySelectorAll('th[data-sa-sort]');
                for (var h = 0; h < headers.length; h++) {
                    (function (th, index) {
                        th.classList.add('is-sortable');
                        th.setAttribute('tabindex', '0');
                        th.setAttribute('role', 'columnheader');
                        th.setAttribute('aria-sort', 'none');

                        var sort = function () {
                            var tbody = table.querySelector('tbody');
                            if (!tbody) {
                                return;
                            }
                            var dir = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
                            var all = table.querySelectorAll('th[data-sa-sort]');
                            for (var o = 0; o < all.length; o++) {
                                all[o].removeAttribute('data-dir');
                                all[o].setAttribute('aria-sort', 'none');
                            }
                            th.setAttribute('data-dir', dir);
                            th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');

                            var colIndex =
                                parseInt(th.getAttribute('data-sa-sort'), 10) || index;
                            var rows = Array.prototype.slice.call(
                                tbody.querySelectorAll('tr')
                            );
                            var type = th.getAttribute('data-type') || 'text';

                            rows.sort(function (a, b) {
                                var ca = a.children[colIndex];
                                var cb = b.children[colIndex];
                                var va = ca
                                    ? (ca.getAttribute('data-sort-value') || ca.textContent).trim()
                                    : '';
                                var vb = cb
                                    ? (cb.getAttribute('data-sort-value') || cb.textContent).trim()
                                    : '';
                                var out;
                                if (type === 'num') {
                                    out =
                                        (parseFloat(va.replace(/[^0-9.\-]/g, '')) || 0) -
                                        (parseFloat(vb.replace(/[^0-9.\-]/g, '')) || 0);
                                } else if (type === 'date') {
                                    out = (Date.parse(va) || 0) - (Date.parse(vb) || 0);
                                } else {
                                    out = va.localeCompare(vb, undefined, {
                                        numeric: true,
                                        sensitivity: 'base'
                                    });
                                }
                                return dir === 'asc' ? out : -out;
                            });

                            for (var r = 0; r < rows.length; r++) {
                                tbody.appendChild(rows[r]);
                            }
                        };

                        th.addEventListener('click', sort);
                        th.addEventListener('keydown', function (e) {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                sort();
                            }
                        });
                    })(headers[h], h);
                }
            })(tables[t]);
        }
    }

    /* ---------- Count-up numbers ---------- */
    function initCounters() {
        var nodes = document.querySelectorAll('[data-sa-count]');
        if (!nodes.length) {
            return;
        }

        var reduce =
            window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function format(value, decimals, prefix, suffix) {
            var n = value.toFixed(decimals);
            var parts = n.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return prefix + parts.join('.') + suffix;
        }

        function run(el) {
            var target = parseFloat(el.getAttribute('data-sa-count')) || 0;
            var decimals = parseInt(el.getAttribute('data-sa-decimals') || '0', 10);
            var prefix = el.getAttribute('data-sa-prefix') || '';
            var suffix = el.getAttribute('data-sa-suffix') || '';

            if (reduce) {
                el.textContent = format(target, decimals, prefix, suffix);
                return;
            }

            var duration = 900;
            var start = null;

            function step(ts) {
                if (!start) {
                    start = ts;
                }
                var p = Math.min((ts - start) / duration, 1);
                var eased = 1 - Math.pow(1 - p, 3);
                el.textContent = format(target * eased, decimals, prefix, suffix);
                if (p < 1) {
                    requestAnimationFrame(step);
                } else {
                    el.textContent = format(target, decimals, prefix, suffix);
                }
            }
            requestAnimationFrame(step);
        }

        if (!('IntersectionObserver' in window)) {
            for (var i = 0; i < nodes.length; i++) {
                run(nodes[i]);
            }
            return;
        }

        var io = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        run(entry.target);
                        io.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.35 }
        );

        for (var j = 0; j < nodes.length; j++) {
            io.observe(nodes[j]);
        }
    }

    /* ---------- CSV export ---------- */
    function initExport() {
        var buttons = document.querySelectorAll('[data-sa-export]');
        for (var i = 0; i < buttons.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var table = document.querySelector(btn.getAttribute('data-sa-export'));
                    if (!table) {
                        return;
                    }
                    var name =
                        btn.getAttribute('data-sa-export-name') || 'optibiz-export';
                    var lines = [];
                    var rows = table.querySelectorAll('tr');
                    for (var r = 0; r < rows.length; r++) {
                        if (rows[r].style.display === 'none') {
                            continue;
                        }
                        var cells = rows[r].querySelectorAll('th, td');
                        if (!cells.length) {
                            continue;
                        }
                        var out = [];
                        for (var c = 0; c < cells.length; c++) {
                            if (cells[c].hasAttribute('data-no-export')) {
                                continue;
                            }
                            var text = (
                                cells[c].getAttribute('data-export-value') ||
                                cells[c].textContent
                            )
                                .replace(/\s+/g, ' ')
                                .trim();
                            out.push('"' + text.replace(/"/g, '""') + '"');
                        }
                        if (out.length) {
                            lines.push(out.join(','));
                        }
                    }
                    var blob = new Blob(['\ufeff' + lines.join('\r\n')], {
                        type: 'text/csv;charset=utf-8;'
                    });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = name + '-' + new Date().toISOString().slice(0, 10) + '.csv';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(function () {
                        URL.revokeObjectURL(url);
                    }, 1200);
                });
            })(buttons[i]);
        }
    }

    /* ---------- Flash alerts ---------- */
    function initAlerts() {
        var alerts = document.querySelectorAll('[data-sa-alert]');
        for (var i = 0; i < alerts.length; i++) {
            (function (alert) {
                var close = alert.querySelector('[data-sa-alert-close]');
                if (close) {
                    close.addEventListener('click', function () {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateY(-8px)';
                        setTimeout(function () {
                            alert.remove();
                        }, 220);
                    });
                }
                if (alert.hasAttribute('data-sa-autohide')) {
                    setTimeout(function () {
                        alert.style.transition = 'opacity .3s ease, transform .3s ease';
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateY(-8px)';
                        setTimeout(function () {
                            alert.remove();
                        }, 320);
                    }, parseInt(alert.getAttribute('data-sa-autohide'), 10) || 6000);
                }
            })(alerts[i]);
        }
    }

    /* ---------- Keyboard shortcuts ---------- */
    function initShortcuts() {
        document.addEventListener('keydown', function (e) {
            var tag = (e.target.tagName || '').toLowerCase();
            var typing =
                tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;

            if (e.key === '/' && !typing) {
                var search = document.querySelector('.sa-search input');
                if (search) {
                    e.preventDefault();
                    search.focus();
                }
            }
        });
    }

    /* ---------- <dialog> open / close ---------- */
    function openDialog(dialog) {
        if (!dialog) {
            return;
        }
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
            dialog.classList.add('is-open-fallback');
        }
    }

    function closeDialog(dialog) {
        if (!dialog) {
            return;
        }
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
            dialog.classList.remove('is-open-fallback');
        }
    }

    function initDialogs() {
        var openers = document.querySelectorAll('[data-sa-open-dialog]');
        for (var i = 0; i < openers.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    openDialog(document.querySelector(btn.getAttribute('data-sa-open-dialog')));
                });
            })(openers[i]);
        }

        var closers = document.querySelectorAll('[data-sa-close-dialog]');
        for (var c = 0; c < closers.length; c++) {
            closers[c].addEventListener('click', function () {
                var node = this;
                while (node && !node.classList.contains('sa-dialog')) {
                    node = node.parentNode;
                }
                closeDialog(node);
            });
        }

        var dialogs = document.querySelectorAll('.sa-dialog');
        for (var d = 0; d < dialogs.length; d++) {
            (function (dialog) {
                // Click on the backdrop (the dialog element itself) closes it
                dialog.addEventListener('click', function (e) {
                    if (e.target === dialog) {
                        closeDialog(dialog);
                    }
                });
            })(dialogs[d]);
        }

        // Expose for page-specific scripts
        window.saOpenDialog = function (selector) {
            openDialog(typeof selector === 'string' ? document.querySelector(selector) : selector);
        };
        window.saCloseDialog = function (selector) {
            closeDialog(typeof selector === 'string' ? document.querySelector(selector) : selector);
        };
    }

    /* ---------- Confirm destructive actions (modal) ---------- */
    function initConfirms() {
        var confirmDialog = document.getElementById('sa-confirm-dialog');
        if (!confirmDialog) {
            confirmDialog = document.createElement('dialog');
            confirmDialog.className = 'sa-dialog';
            confirmDialog.id = 'sa-confirm-dialog';
            confirmDialog.innerHTML = '' +
                '<div class="sa-dialog-inner" style="padding:24px 26px;max-width:480px;border-radius:var(--sa-radius);background:var(--sa-surface-solid);">' +
                '<div class="sa-dialog-body" style="padding:0;margin-bottom:20px;"><p id="sa-confirm-msg" style="margin:0;font-size:14.5px;color:var(--sa-heading);line-height:1.55;font-weight:550;"></p></div>' +
                '<div style="display:flex;gap:10px;justify-content:flex-end;">' +
                '<button type="button" class="sa-btn sa-btn-ghost sa-dialog-close">Cancel</button>' +
                '<button type="button" class="sa-btn sa-btn-danger sa-dialog-confirm">Confirm</button>' +
                '</div>' +
                '</div>';
            document.body.appendChild(confirmDialog);
            confirmDialog.querySelector('.sa-dialog-close').addEventListener('click', function () {
                closeDialog(confirmDialog);
            });
        }

        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('[data-sa-confirm]') : null;
            if (!el) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();

            var msg = el.getAttribute('data-sa-confirm') || 'Are you sure?';
            var msgNode = confirmDialog.querySelector('#sa-confirm-msg');
            if (msgNode) msgNode.textContent = msg;

            var href = el.getAttribute('href');
            var okBtn = confirmDialog.querySelector('.sa-dialog-confirm');

            okBtn.onclick = function () {
                closeDialog(confirmDialog);
                if (href) {
                    window.location.href = href;
                }
            };

            openDialog(confirmDialog);
        });
    }

    /* ---------- Dedicated Superadmin Logout Modal ---------- */
    function initLogoutModal() {
        var modal = document.getElementById('saLogoutModal');
        if (!modal) return;

        // Open modal on click of any logout trigger
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest ? e.target.closest('[data-sa-logout-trigger]') : null;
            if (!trigger) return;

            e.preventDefault();
            e.stopPropagation();

            var href = trigger.getAttribute('href');
            var confirmBtn = modal.querySelector('.sa-logout-btn-confirm');
            if (href && confirmBtn) {
                confirmBtn.setAttribute('href', href);
            }

            openDialog(modal);
        });

        // Close on cancel buttons
        var closers = modal.querySelectorAll('[data-sa-close-dialog]');
        for (var i = 0; i < closers.length; i++) {
            closers[i].addEventListener('click', function (e) {
                e.preventDefault();
                closeDialog(modal);
            });
        }
    }

    /* ---------- Back to top ---------- */
    function initBackToTop() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-sa-totop]') : null;
            if (!btn) {
                return;
            }
            e.preventDefault();
            var content = document.getElementById('saContent') || document.querySelector('.sa-main') || window;
            if (content && content.scrollTo) {
                content.scrollTo({ top: 0, behavior: 'smooth' });
            }
            if (window.scrollTo) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    }

    /* ---------- Card Collapse / Expand ---------- */
    /* ---------- Card collapse / expand ---------- */
    function initCardCollapse() {
        // Polyfill for Element.prototype.closest (for older browsers)
        if (!Element.prototype.closest) {
            Element.prototype.closest = function(s) {
                var el = this;
                do {
                    if (Element.prototype.matches.call(el, s)) return el;
                    el = el.parentElement || el.parentNode;
                } while (el !== null && el.nodeType === 1);
                return null;
            };
        }

        // Restore saved collapsed states
        var cards = document.querySelectorAll('.sa-card[data-card-id]');
        cards.forEach(function (card) {
            var cardId = card.getAttribute('data-card-id');
            var saved = null;
            if (cardId && typeof localStorage !== 'undefined') {
                try {
                    saved = localStorage.getItem('sa_card_' + cardId);
                } catch (err) {}
            }
            if (saved === 'collapsed') {
                card.classList.add('sa-card-collapsed');
                var btn = card.querySelector('.sa-card-toggle:not([data-action="refresh"]):not([onclick])');
                if (btn) {
                    btn.setAttribute('aria-expanded', 'false');
                    btn.setAttribute('title', 'Expand card');
                }
            }
        });

        // Click delegation
        document.addEventListener('click', function (e) {
            var toggleBtn = e.target.closest('.sa-card-toggle');
            if (!toggleBtn) return;
            if (toggleBtn.getAttribute('onclick') || toggleBtn.getAttribute('data-action') === 'refresh') return;

            var card = toggleBtn.closest('.sa-card');
            if (card) {
                e.preventDefault();
                e.stopPropagation();
                var isCollapsed = card.classList.toggle('sa-card-collapsed');
                toggleBtn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                toggleBtn.setAttribute('title', isCollapsed ? 'Expand card' : 'Collapse card');

                var cardId = card.getAttribute('data-card-id');
                if (cardId && typeof localStorage !== 'undefined') {
                    try {
                        localStorage.setItem('sa_card_' + cardId, isCollapsed ? 'collapsed' : 'expanded');
                    } catch (err) {}
                }
            }
        });
    }

    /* ---------- Horizontal table scroll indicators ---------- */
    function initTableScroll() {
        var wraps = document.querySelectorAll('.sa-table-wrap');
        if (!wraps.length) return;

        function updateWrap(wrap) {
            var maxScroll = wrap.scrollWidth - wrap.clientWidth;
            if (maxScroll > 2) {
                wrap.classList.add('has-scroll');
                wrap.classList.toggle('can-scroll-left', wrap.scrollLeft > 4);
                wrap.classList.toggle('can-scroll-right', wrap.scrollLeft < maxScroll - 4);
            } else {
                wrap.classList.remove('has-scroll', 'can-scroll-left', 'can-scroll-right');
            }
        }

        for (var i = 0; i < wraps.length; i++) {
            (function (wrap) {
                wrap.addEventListener('scroll', function () {
                    updateWrap(wrap);
                }, { passive: true });
                updateWrap(wrap);
            })(wraps[i]);
        }

        window.addEventListener('resize', function () {
            for (var j = 0; j < wraps.length; j++) {
                updateWrap(wraps[j]);
            }
        }, { passive: true });
    }

    onReady(function () {
        initTheme();
        initSidebar();
        initMenus();
        initNotifications();
        initTableSearch();
        initTableSort();
        initCounters();
        initExport();
        initAlerts();
        initDialogs();
        initShortcuts();
        initConfirms();
        initBackToTop();
        initCardCollapse();
        initTableScroll();
        initLogoutModal();
    });
})();
