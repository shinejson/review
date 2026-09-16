/* ============================================================
   Optibiz Admin — shell behavior
   ------------------------------------------------------------
   * theme toggle (dark / light) persisted in localStorage,
     first visit follows the OS preference
   * sidebar drawer (mobile)
   * notification dropdown
   * user profile dropdown
   * search bar with keyboard shortcut (/)
   * click outside to close dropdowns

   Loaded at the end of <body> on every admin page.
   ============================================================ */
(function () {
    'use strict';

    var THEME_KEY = 'optibiz-admin-theme';

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
        var nodes = document.querySelectorAll('[data-admin-theme]');
        for (var i = 0; i < nodes.length; i++) {
            var btn = nodes[i];
            var next = theme === 'light' ? 'dark' : 'light';
            btn.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
            btn.setAttribute('aria-label', 'Switch to ' + next + ' theme');
            btn.setAttribute('title', 'Switch to ' + next + ' theme');
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
        var theme = stored === 'light' || stored === 'dark'
            ? stored
            : window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches
            ? 'light'
            : 'dark';

        setTheme(theme, false);

        var buttons = document.querySelectorAll('[data-admin-theme]');
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
    }

    /* ---------- Sidebar collapse (desktop resize) ---------- */
    function initCollapse() {
        var btn = document.querySelector('[data-admin-collapse]');
        var app = document.querySelector('.admin-app');
        if (!btn || !app) {
            return;
        }

        var COLLAPSE_KEY = 'optibiz-admin-sidebar-collapsed';

        function applyCollapsed(collapsed, persist) {
            app.classList.toggle('is-collapsed', collapsed);
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            btn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            if (persist !== false) {
                try {
                    localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
                } catch (e) { /* ignore */ }
            }
        }

        // Restore saved preference
        var saved = null;
        try {
            saved = localStorage.getItem(COLLAPSE_KEY);
        } catch (e) {
            saved = null;
        }
        if (saved === '1') {
            applyCollapsed(true, false);
        }

        btn.addEventListener('click', function () {
            applyCollapsed(!app.classList.contains('is-collapsed'));
        });
    }

    /* ---------- Mobile sidebar ---------- */
    function initSidebar() {
        var burger = document.querySelector('.admin-burger');
        var sidebar = document.querySelector('.admin-sidebar');
        var backdrop = document.querySelector('[data-admin-backdrop]');
        if (!burger || !sidebar) {
            return;
        }

        function setSidebarState(open) {
            var isOpen = typeof open === 'boolean' ? open : !sidebar.classList.contains('open');
            sidebar.classList.toggle('open', isOpen);
            document.body.classList.toggle('admin-sidebar-open', isOpen);
            burger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        burger.addEventListener('click', function (e) {
            e.stopPropagation();
            setSidebarState();
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                setSidebarState(false);
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function (e) {
            if (window.innerWidth > 768) {
                return;
            }
            if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !burger.contains(e.target)) {
                setSidebarState(false);
            }
        });

        // Close on ESC key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                setSidebarState(false);
            }
        });

        // Auto close on window resize above mobile breakpoint
        window.addEventListener('resize', function () {
            if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
                setSidebarState(false);
            }
        });
    }

    /* ---------- Notification dropdown ---------- */
    function initNotifications() {
        var notifWraps = document.querySelectorAll('.admin-notification-wrap');
        if (!notifWraps.length) {
            return;
        }

        function closeAllNotifications(except) {
            for (var i = 0; i < notifWraps.length; i++) {
                if (notifWraps[i] === except) {
                    continue;
                }
                notifWraps[i].classList.remove('is-open');
                var btn = notifWraps[i].querySelector('[data-admin-notification-trigger]');
                if (btn) {
                    btn.setAttribute('aria-expanded', 'false');
                }
            }
        }

        for (var i = 0; i < notifWraps.length; i++) {
            (function (wrap) {
                var btn = wrap.querySelector('[data-admin-notification-trigger]');
                if (!btn) {
                    return;
                }
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var isOpen = wrap.classList.toggle('is-open');
                    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    closeAllNotifications(wrap);
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

    /* ---------- User profile dropdown ---------- */
    function initMenus() {
        var menuWraps = document.querySelectorAll('[data-admin-menu]');
        if (!menuWraps.length) {
            return;
        }

        function closeAllMenus(except) {
            for (var i = 0; i < menuWraps.length; i++) {
                if (menuWraps[i] === except) {
                    continue;
                }
                menuWraps[i].classList.remove('is-open');
                var trigger = menuWraps[i].querySelector('[data-admin-menu-trigger]');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                }
            }
        }

        for (var i = 0; i < menuWraps.length; i++) {
            (function (wrap) {
                var trigger = wrap.querySelector('[data-admin-menu-trigger]');
                if (!trigger) {
                    return;
                }
                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var isOpen = wrap.classList.toggle('is-open');
                    trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    closeAllMenus(wrap);
                });
            })(menuWraps[i]);
        }

        document.addEventListener('click', function () {
            closeAllMenus(null);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAllMenus(null);
            }
        });
    }

    /* ---------- Search (live page filtering) ---------- */
    function initSearch() {
        var searchInput = document.querySelector('.admin-search input');
        if (!searchInput) {
            return;
        }

        // Bind the topnav search to the first table on the page that has
        // filterable rows; fall back to the first table with a body.
        function resolveTarget() {
            var tables = document.querySelectorAll('table');
            var i;
            for (i = 0; i < tables.length; i++) {
                if (tables[i].querySelector('tbody tr[data-filterable], tbody tr[data-search]')) {
                    return tables[i];
                }
            }
            for (i = 0; i < tables.length; i++) {
                if (tables[i].querySelector('tbody tr:not([data-static])')) {
                    return tables[i];
                }
            }
            return null;
        }

        var target = resolveTarget();
        var rows = [];
        if (target) {
            rows = target.querySelectorAll('tbody tr[data-filterable], tbody tr[data-search]');
            if (!rows.length) {
                rows = target.querySelectorAll('tbody tr:not([data-static])');
            }
        }

        // Live filter as the admin types
        searchInput.addEventListener('input', function () {
            if (!rows.length) {
                return;
            }
            var q = searchInput.value.trim().toLowerCase();
            for (var r = 0; r < rows.length; r++) {
                var text = (rows[r].getAttribute('data-search') || rows[r].textContent).toLowerCase();
                var match = !q || text.indexOf(q) !== -1;
                rows[r].style.display = match ? '' : 'none';
            }
        });

        document.addEventListener('keydown', function (e) {
            // Don't trigger if user is already typing in an input
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }
            if (e.key === '/') {
                e.preventDefault();
                searchInput.focus();
            }
        });

        // Clear search on Escape (and restore hidden rows)
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                searchInput.blur();
                searchInput.value = '';
                for (var r = 0; r < rows.length; r++) {
                    rows[r].style.display = '';
                }
            }
        });
    }

    /* ---------- Social composer ---------- */
    function initComposer() {
        var input = document.querySelector('[data-social-input]');
        if (!input) {
            return;
        }

        var counter = document.querySelector('[data-social-counter]');
        var limit = parseInt(input.getAttribute('data-social-limit'), 10) || 0;

        function format(n) {
            return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function sync() {
            if (!counter) {
                return;
            }
            var used = input.value.length;
            counter.textContent = format(used) + ' / ' + format(limit) + ' characters';
            if (limit > 0 && used > limit) {
                counter.classList.add('is-over');
                counter.textContent += ' — ' + format(used - limit) + ' over the limit';
            } else {
                counter.classList.remove('is-over');
            }
        }

        input.addEventListener('input', sync);
        sync();

        var copyBtn = document.querySelector('[data-social-copy]');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var done = function () {
                    var label = copyBtn.textContent;
                    copyBtn.textContent = 'Copied ✓';
                    setTimeout(function () {
                        copyBtn.textContent = label;
                    }, 1600);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(done, function () {
                        input.select();
                    });
                } else {
                    input.select();
                    try {
                        document.execCommand('copy');
                        done();
                    } catch (e) {
                        /* nothing else we can do */
                    }
                }
            });
        }
    }

    /* ---------- Modal confirm (sign out) ---------- */
    function openAdminDialog(dialog) {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
            dialog.classList.add('is-open-fallback');
        }
    }

    function closeAdminDialog(dialog) {
        if (!dialog) return;
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
            dialog.classList.remove('is-open-fallback');
        }
    }

    function initConfirms() {
        var dlg = document.getElementById('admin-confirm-dialog');
        if (!dlg) {
            dlg = document.createElement('dialog');
            dlg.id = 'admin-confirm-dialog';
            dlg.innerHTML = '' +
                '<form method="dialog" class="admin-confirm-form" style="padding:20px;border-radius:8px;max-width:460px;">' +
                '<p id="admin-confirm-msg" style="margin:0 0 16px;font-size:15px;color:var(--ink, #111);"></p>' +
                '<menu style="display:flex;gap:8px;justify-content:flex-end;">' +
                '<button type="button" class="admin-confirm-cancel">Cancel</button>' +
                '<button type="submit" class="admin-confirm-ok">Continue</button>' +
                '</menu>' +
                '</form>';
            document.body.appendChild(dlg);
            dlg.querySelector('.admin-confirm-cancel').addEventListener('click', function () {
                closeAdminDialog(dlg);
            });
        }

        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('[data-admin-confirm]') : null;
            if (!el) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();

            var msg = el.getAttribute('data-admin-confirm') || 'Are you sure?';
            var msgNode = dlg.querySelector('#admin-confirm-msg');
            if (msgNode) {
                msgNode.textContent = msg;
            }

            var href = el.getAttribute('href');
            var okBtn = dlg.querySelector('.admin-confirm-ok');

            okBtn.onclick = function (ev) {
                ev.preventDefault();
                closeAdminDialog(dlg);
                if (href) {
                    window.location.href = href;
                }
                return false;
            };

            openAdminDialog(dlg);
        });
    }

    /* ---------- Dedicated Logout Confirmation Modal ---------- */
    function initLogoutModal() {
        var modal = document.getElementById('adminLogoutModal');
        if (!modal) return;

        // Open modal on click of any logout trigger
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest ? e.target.closest('[data-admin-logout-trigger]') : null;
            if (!trigger) return;

            e.preventDefault();
            e.stopPropagation();

            // Also copy the href if trigger has custom logout URL
            var href = trigger.getAttribute('href');
            var confirmBtn = modal.querySelector('.btn-logout-confirm');
            if (href && confirmBtn) {
                confirmBtn.setAttribute('href', href);
            }

            openAdminDialog(modal);
        });

        // Close modal buttons
        var closers = modal.querySelectorAll('[data-admin-logout-close]');
        for (var i = 0; i < closers.length; i++) {
            closers[i].addEventListener('click', function (e) {
                e.preventDefault();
                closeAdminDialog(modal);
            });
        }

        // Backdrop click
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeAdminDialog(modal);
            }
        });

        // Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && (modal.hasAttribute('open') || modal.open)) {
                closeAdminDialog(modal);
            }
        });
    }

    onReady(function () {
        initTheme();
        initCollapse();
        initSidebar();
        initNotifications();
        initMenus();
        initSearch();
        initComposer();
        initConfirms();
        initLogoutModal();
    });
})();