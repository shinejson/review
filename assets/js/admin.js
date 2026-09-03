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

    /* ---------- Mobile sidebar ---------- */
    function initSidebar() {
        var burger = document.querySelector('.admin-burger');
        var sidebar = document.querySelector('.admin-sidebar');
        if (!burger || !sidebar) {
            return;
        }

        burger.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function (e) {
            if (window.innerWidth > 768) {
                return;
            }
            if (!sidebar.contains(e.target) && !burger.contains(e.target)) {
                sidebar.classList.remove('open');
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

    /* ---------- Search shortcut ---------- */
    function initSearch() {
        var searchInput = document.querySelector('.admin-search input');
        if (!searchInput) {
            return;
        }

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

        // Clear search on Escape
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                searchInput.blur();
                searchInput.value = '';
            }
        });
    }

    onReady(function () {
        initTheme();
        initSidebar();
        initNotifications();
        initMenus();
        initSearch();
    });
})();