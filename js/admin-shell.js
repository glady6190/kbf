/* ============================================
 * KBF-UK ADMIN SHELL
 * One shared header and page guard for every page in admin/ and clubadmin/.
 *
 *   <div id="kbf-admin-header"></div>            where the header goes
 *   <script src="/js/admin-shell.js"></script>    before the page's own script
 *
 *   KBFShell.start({
 *       anyOf: ['content.broadcasts'],   // any one opens the page; [] = any signed-in staff
 *       superAdminOnly: false,           // true: Super Admins only
 *       active: 'broadcasts'             // menu item to highlight
 *   }).then(function (user) { ... load the page ... });
 *
 * The promise only resolves when the check passes. Otherwise the browser is
 * sent to /login (not signed in), /change-password (forced change) or
 * /admin/dashboard (missing permission), and the page stays hidden.
 *
 * user: { id, name, email, isSuperAdmin, permissions, staffRoles,
 *         has(key), hasAny([keys]), mainLabel, impersonation }
 * Super Admins have every permission.
 *
 * Impersonation: while a Super Admin views the site as someone else, the
 * session's user IS that person, so the header, menu and guard follow their
 * permissions. A fixed amber banner above the header says so and offers
 * "Return to my account". KBFShell.openImpersonateDialog({ userId, name })
 * starts it from the Staff pages.
 *
 * Every value from the server is written with textContent or escaped.
 * ============================================ */
(function () {
    'use strict';

    // Hide the page until the guard passes (pages already start hidden too)
    document.documentElement.style.visibility = 'hidden';

    var PERMISSION_LABELS = {
        'players.approve_registrations': 'Approve player registrations',
        'players.view_export': 'View and export the player list',
        'players.edit_details': 'Edit player details (not grade)',
        'players.change_grades': 'Change player grades',
        'players.contact_details': 'See player emails and phone numbers',
        'tournaments.organise_own': 'Organise their own tournaments',
        'tournaments.approve': 'Approve tournaments and results',
        'tournaments.manage_all': 'Manage all tournaments and results',
        'content.own_news_gallery': 'News and gallery for their own tournaments',
        'content.all_news_gallery': 'All news and gallery',
        'content.sponsors': 'Sponsors',
        'content.broadcasts': 'Email broadcasts',
        'control.email': 'Email and limits',
        'control.grading': 'Grading and seasons',
        'control.notifications': 'Notifications and emails',
        'control.reports': 'Reports and exports',
        'control.site_settings': 'Site settings',
        'control.activity_log': 'Activity log'
    };
    var PERMISSION_ORDER = Object.keys(PERMISSION_LABELS);

    // ---------- control centre ----------
    // Sidebar sections in order, each shown to the people allowed to use it.
    // Overview: anyone with a control.* permission (and Super Admins).
    // Security and Staff and access: Super Admins only.
    var CONTROL_KEYS = ['control.email', 'control.grading', 'control.notifications',
        'control.reports', 'control.site_settings', 'control.activity_log'];
    var CONTROL_SECTIONS = [
        { key: 'overview', label: 'Overview', href: '/admin/overview', anyOf: CONTROL_KEYS, icon: 'grid' },
        { key: 'grading', label: 'Grading and seasons', href: '/admin/grading-settings', anyOf: ['control.grading'], icon: 'chart' },
        { key: 'notifications', label: 'Notifications', href: '/admin/notifications', anyOf: ['control.notifications'], icon: 'bell' },
        { key: 'email', label: 'Email and limits', href: '/admin/email-limits', anyOf: ['control.email'], icon: 'mail' },
        { key: 'reports', label: 'Reports and exports', href: '/admin/reports', anyOf: ['control.reports'], icon: 'download' },
        { key: 'site', label: 'Site settings', href: '/admin/site-settings', anyOf: ['control.site_settings'], icon: 'cog' },
        { key: 'activity', label: 'Activity log', href: '/admin/activity-log', anyOf: ['control.activity_log'], icon: 'list' },
        { key: 'security', label: 'Security', href: '/admin/security', superAdminOnly: true, icon: 'shield' },
        { key: 'staff', label: 'Staff and access', href: '/admin/people?tab=staff', superAdminOnly: true, icon: 'users' }
    ];

    var ROLE_LABELS = { MainAdmin: 'Main Admin', ZonalAdmin: 'Zonal Admin', Organiser: 'Organiser' };

    // Menu order and who sees each item
    var MENU = [
        { key: 'dashboard', label: 'Dashboard', href: '/admin/dashboard', anyOf: [] },
        { key: 'people', label: 'People', href: '/admin/people', anyOf: ['players.view_export', 'players.edit_details'] },
        { key: 'approvals', label: 'Approvals', href: '/admin/approvals', anyOf: ['players.approve_registrations', 'tournaments.approve'] },
        { key: 'tournaments', label: 'Tournaments', href: '/admin/tournaments', anyOf: ['tournaments.approve', 'tournaments.manage_all'] },
        { key: 'my-tournaments', label: 'My tournaments', href: '/clubadmin/tournaments', anyOf: ['tournaments.organise_own'] },
        { key: 'grading', label: 'Grading', href: '/admin/rankings', anyOf: ['players.change_grades'] },
        { key: 'news', label: 'News', href: '/admin/announcements', anyOf: ['content.all_news_gallery'] },
        { key: 'my-posts', label: 'My posts', href: '/clubadmin/posts', anyOf: ['content.own_news_gallery'] },
        { key: 'broadcasts', label: 'Broadcasts', href: '/admin/broadcasts', anyOf: ['content.broadcasts'] },
        { key: 'sponsors', label: 'Sponsors', href: '/admin/sponsors', anyOf: ['content.sponsors'] },
        { key: 'staff', label: 'Staff', href: '/admin/people?tab=staff', superAdminOnly: true },
        // Opens the first control centre section the person can use
        { key: 'settings', label: 'Settings', href: '/admin/security', controlCentre: true }
    ];
    var MAX_BAR_ITEMS = 7;

    var THEMES = [
        { key: 'emerald', cls: 'bg-emerald-500' },
        { key: 'cyan', cls: 'bg-cyan-500' },
        { key: 'yellow', cls: 'bg-yellow-400' },
        { key: 'orange', cls: 'bg-orange-500' }
    ];

    var ICONS = {
        menu: 'M4 6h16M4 12h16M4 18h16',
        close: 'M6 18L18 6M6 6l12 12',
        chevron: 'M19 9l-7 7-7-7',
        user: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        key: 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
        signout: 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1',
        shield: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        eye: 'M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
        grid: 'M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z',
        chart: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        bell: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
        mail: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        download: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
        cog: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
        list: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
        users: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'
    };

    // ---------- helpers ----------
    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function icon(name, cls) {
        return '<svg class="' + (cls || 'w-4 h-4') + '" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">' +
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' + ICONS[name] + '"/></svg>';
    }

    function csrfToken() {
        try { return sessionStorage.getItem('csrf_token') || ''; } catch (e) { return ''; }
    }

    function go(url) {
        window.location.href = url;
    }

    function injectStyles() {
        if (document.getElementById('kbf-shell-styles')) return;
        var style = document.createElement('style');
        style.id = 'kbf-shell-styles';
        style.textContent =
            '.kbf-nav-link{display:inline-block;padding:.5rem .75rem;color:#9ca3af;font-weight:700;text-transform:uppercase;font-size:.75rem;white-space:nowrap;transition:color .2s ease;}' +
            '.kbf-nav-link:hover{color:#fff;}' +
            '.kbf-nav-link.kbf-active{background:var(--theme-primary);color:#0F172A;font-weight:900;}' +
            '.kbf-drop{position:absolute;right:0;top:100%;margin-top:.5rem;min-width:13rem;background:#0f172a;border:2px solid #374151;z-index:60;box-shadow:6px 6px 0 var(--theme-primary);}' +
            '.kbf-drop a,.kbf-drop button{display:flex;align-items:center;gap:.5rem;width:100%;text-align:left;padding:.625rem .875rem;color:#d1d5db;font-weight:700;text-transform:uppercase;font-size:.75rem;}' +
            '.kbf-drop a:hover,.kbf-drop button:hover{background:#1e293b;color:#fff;}' +
            '.kbf-drop a.kbf-active{background:var(--theme-primary);color:#0F172A;}' +
            '.kbf-theme-btn{width:24px;height:24px;border-radius:50%;border:2px solid transparent;cursor:pointer;transition:transform .2s ease;}' +
            '.kbf-theme-btn:hover{transform:scale(1.1);border-color:#fff;}' +
            '.kbf-theme-btn.kbf-active{border-color:#fff;box-shadow:0 0 8px currentColor;}' +
            '.kbf-mobile-link{display:block;padding:.625rem 1rem;background:#1e293b;color:#d1d5db;font-weight:700;text-transform:uppercase;font-size:.875rem;}' +
            '.kbf-mobile-link:hover{color:#fff;}' +
            '.kbf-mobile-link.kbf-active{background:var(--theme-primary);color:#0F172A;font-weight:900;}' +
            '.kbf-input{width:100%;background:#1e293b;border:2px solid #374151;color:#fff;padding:.625rem .875rem;font-weight:700;font-size:.875rem;}' +
            '.kbf-input:focus{outline:none;border-color:var(--theme-primary);}' +
            '.kbf-btn-theme{background:var(--theme-primary);color:#0F172A;}' +
            // Impersonation banner: fixed amber colours on every theme
            '.kbf-imp-banner{background:repeating-linear-gradient(135deg,#F59E0B 0,#F59E0B 18px,#FBBF24 18px,#FBBF24 36px);color:#0F172A;}' +
            '.kbf-imp-icon{background:#0F172A;color:#FBBF24;}' +
            '.kbf-imp-btn{background:#0F172A;color:#FFFFFF;border:2px solid #0F172A;}' +
            '.kbf-imp-btn:hover{background:#1e293b;}' +
            '.kbf-imp-btn:disabled{opacity:.6;cursor:wait;}' +
            // Control centre sidebar
            '.kbf-cc-link{display:flex;align-items:center;gap:.625rem;padding:.625rem .75rem;color:#9ca3af;font-weight:700;text-transform:uppercase;font-size:.75rem;transition:color .2s ease,background-color .2s ease;}' +
            '.kbf-cc-link:hover{color:#fff;background:#1e293b;}' +
            '.kbf-cc-link.kbf-active{background:var(--theme-primary);color:#0F172A;font-weight:900;}' +
            '.kbf-cc-toggle{display:flex;align-items:center;justify-content:space-between;gap:.75rem;width:100%;padding:.75rem 1rem;background:#0f172a;border:2px solid #374151;color:#fff;font-weight:900;text-transform:uppercase;font-size:.75rem;}' +
            '.kbf-cc-toggle:hover{border-color:#6b7280;}';
        document.head.appendChild(style);
    }

    // ---------- user ----------
    function buildUser(u) {
        var permissions = Array.isArray(u.permissions) ? u.permissions.slice() : [];
        var staffRoles = Array.isArray(u.staffRoles) ? u.staffRoles.slice() : [];
        var isSuper = u.isSuperAdmin === true;
        var user = {
            id: u.id,
            name: u.name || '',
            email: u.email || '',
            isSuperAdmin: isSuper,
            twoStepEnabled: u.twoStepEnabled === true,
            permissions: isSuper ? PERMISSION_ORDER.slice() : permissions,
            staffRoles: staffRoles,
            has: function (key) { return isSuper || permissions.indexOf(key) !== -1; },
            hasAny: function (keys) {
                if (!keys || keys.length === 0) return true;
                for (var i = 0; i < keys.length; i++) { if (user.has(keys[i])) return true; }
                return false;
            }
        };
        user.mainLabel = isSuper ? 'Super Admin' : (staffRoles.length ? (ROLE_LABELS[staffRoles[0]] || staffRoles[0]) : 'Staff');
        user.impersonation = null;
        return user;
    }

    // Control centre sections this person can open
    function visibleControlSections(user) {
        return CONTROL_SECTIONS.filter(function (s) {
            if (s.superAdminOnly) return user.isSuperAdmin;
            return user.hasAny(s.anyOf);
        });
    }

    function hasControlAccess(user) {
        if (user.isSuperAdmin) return true;
        return CONTROL_KEYS.some(function (key) { return user.has(key); });
    }

    function visibleMenu(user) {
        var items = [];
        MENU.forEach(function (item) {
            if (item.controlCentre) {
                // Super Admins and anyone with a control.* permission
                if (!hasControlAccess(user)) return;
                var sections = visibleControlSections(user);
                if (!sections.length) return;
                items.push({ key: item.key, label: item.label, href: sections[0].href });
                return;
            }
            if (item.superAdminOnly ? user.isSuperAdmin : user.hasAny(item.anyOf)) items.push(item);
        });
        return items;
    }

    // ---------- control centre sidebar ----------
    // Page markup: <aside id="kbf-control-nav"></aside> beside the content.
    // On phones it becomes a menu button at the top of the page.
    function renderControlNav(user, activeKey) {
        var host = document.getElementById('kbf-control-nav');
        if (!host) return;
        var sections = visibleControlSections(user);
        var current = null;
        sections.forEach(function (s) { if (s.key === activeKey) current = s; });

        var links = sections.map(function (s) {
            var on = s.key === activeKey;
            return '<li><a href="' + s.href + '" class="kbf-cc-link' + (on ? ' kbf-active' : '') + '"' + (on ? ' aria-current="page"' : '') + '>' +
                icon(s.icon, 'w-4 h-4 flex-shrink-0') + '<span>' + escapeHtml(s.label) + '</span></a></li>';
        }).join('');

        host.innerHTML =
            '<nav aria-label="Control centre">' +
                '<button type="button" class="kbf-cc-toggle lg:hidden" data-kbf-cc-toggle aria-expanded="false" aria-controls="kbf-cc-list">' +
                    '<span class="flex items-center gap-2 min-w-0">' + icon('menu', 'w-4 h-4 flex-shrink-0') +
                        '<span class="truncate">Control centre<span class="text-gray-400" data-kbf-cc-current></span></span>' +
                    '</span>' +
                    icon('chevron', 'w-4 h-4 flex-shrink-0') +
                '</button>' +
                '<div id="kbf-cc-list" class="hidden lg:block mt-2 lg:mt-0 bg-slate-900 border-2 sm:border-4 border-gray-700 p-2" data-kbf-cc-list>' +
                    '<p class="hidden lg:block px-3 pt-2 pb-3 text-xs font-black uppercase tracking-widest" style="color:var(--theme-primary)">Control centre</p>' +
                    '<ul class="space-y-1">' + links + '</ul>' +
                '</div>' +
            '</nav>';

        if (current) {
            host.querySelector('[data-kbf-cc-current]').textContent = ': ' + current.label;
        }

        var toggle = host.querySelector('[data-kbf-cc-toggle]');
        var list = host.querySelector('[data-kbf-cc-list]');
        toggle.addEventListener('click', function () {
            var open = list.classList.contains('hidden');
            list.classList.toggle('hidden', !open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // ---------- theme ----------
    function applyTheme(theme) {
        var valid = THEMES.some(function (t) { return t.key === theme; });
        if (!valid) theme = 'emerald';
        document.body.setAttribute('data-theme', theme);
        try { localStorage.setItem('badminton-theme', theme); } catch (e) { /* ignore */ }
        document.querySelectorAll('.kbf-theme-btn, .theme-switcher-btn').forEach(function (btn) {
            var on = btn.getAttribute('data-theme') === theme;
            btn.classList.toggle('kbf-active', on && btn.classList.contains('kbf-theme-btn'));
            btn.classList.toggle('active', on && btn.classList.contains('theme-switcher-btn'));
        });
    }

    function themeButtons() {
        return THEMES.map(function (t) {
            return '<button type="button" class="kbf-theme-btn ' + t.cls + '" data-theme="' + t.key + '" aria-label="' + t.key + ' theme"></button>';
        }).join('');
    }

    // ---------- header ----------
    function renderHeader(user, active) {
        var items = visibleMenu(user);
        var barItems = items.slice(0, MAX_BAR_ITEMS);
        var moreItems = items.slice(MAX_BAR_ITEMS);
        var moreActive = moreItems.some(function (i) { return i.key === active; });

        var bar = barItems.map(function (i) {
            return '<a href="' + i.href + '" class="kbf-nav-link' + (i.key === active ? ' kbf-active' : '') + '">' + escapeHtml(i.label) + '</a>';
        }).join('');

        var more = moreItems.length ?
            '<div class="relative" data-kbf-dropdown>' +
                '<button type="button" class="kbf-nav-link flex items-center gap-1' + (moreActive ? ' kbf-active' : '') + '" data-kbf-toggle aria-haspopup="true" aria-expanded="false">More ' + icon('chevron', 'w-3 h-3') + '</button>' +
                '<div class="kbf-drop hidden" data-kbf-menu>' +
                    moreItems.map(function (i) {
                        return '<a href="' + i.href + '"' + (i.key === active ? ' class="kbf-active"' : '') + '>' + escapeHtml(i.label) + '</a>';
                    }).join('') +
                '</div>' +
            '</div>' : '';

        var account =
            '<div class="relative" data-kbf-dropdown>' +
                '<button type="button" class="flex items-center gap-2 px-3 py-2 bg-slate-800 border-2 border-gray-700 hover:border-gray-500 transition" data-kbf-toggle aria-haspopup="true" aria-expanded="false">' +
                    '<span class="theme-primary-text" style="color:var(--theme-primary)">' + icon('user', 'w-4 h-4') + '</span>' +
                    '<span class="text-left leading-tight">' +
                        '<span class="block font-bold uppercase text-xs text-white max-w-[10rem] truncate" data-kbf-name></span>' +
                        '<span class="block font-bold uppercase text-[10px] text-gray-400" data-kbf-label></span>' +
                    '</span>' +
                    icon('chevron', 'w-3 h-3') +
                '</button>' +
                '<div class="kbf-drop hidden" data-kbf-menu>' +
                    '<button type="button" data-kbf-action="password">' + icon('key') + 'Change password</button>' +
                    '<a href="/admin/two-step">' + icon('shield') + '2-step verification</a>' +
                    '<button type="button" data-kbf-action="signout">' + icon('signout') + 'Sign out</button>' +
                '</div>' +
            '</div>';

        var mobileLinks = items.map(function (i) {
            return '<a href="' + i.href + '" class="kbf-mobile-link' + (i.key === active ? ' kbf-active' : '') + '">' + escapeHtml(i.label) + '</a>';
        }).join('');

        var html =
            '<nav class="bg-slate-900 border-b-4 theme-primary-border shadow-2xl" style="border-color:var(--theme-primary)">' +
                '<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">' +
                    '<div class="flex justify-between items-center h-16 sm:h-20 gap-4">' +
                        '<a href="/admin/dashboard" class="flex items-center gap-3 min-w-0">' +
                            '<img src="/assets/images/logo/KBF-Logo.jpeg" alt="KBF-UK Logo" class="h-10 w-10 sm:h-12 sm:w-12 object-contain flex-shrink-0">' +
                            '<span class="text-base sm:text-xl font-black uppercase whitespace-nowrap">KBF-UK Admin</span>' +
                        '</a>' +
                        '<div class="hidden lg:flex items-center gap-1 flex-1 justify-center">' + bar + more + '</div>' +
                        '<div class="hidden lg:flex items-center gap-3">' +
                            '<div class="flex items-center gap-2">' + themeButtons() + '</div>' +
                            account +
                        '</div>' +
                        '<button type="button" class="lg:hidden p-2 text-gray-400 hover:text-white" data-kbf-mobile-toggle aria-label="Open menu" aria-expanded="false">' +
                            '<span data-kbf-icon-open>' + icon('menu', 'w-6 h-6') + '</span>' +
                            '<span data-kbf-icon-close class="hidden">' + icon('close', 'w-6 h-6') + '</span>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="lg:hidden hidden border-t-2 border-gray-700" data-kbf-mobile-menu>' +
                    '<div class="px-4 py-4 space-y-2">' +
                        '<div class="flex items-center justify-between mb-3 gap-3">' +
                            '<div class="min-w-0">' +
                                '<span class="block font-bold uppercase text-sm truncate" style="color:var(--theme-primary)" data-kbf-name></span>' +
                                '<span class="block font-bold uppercase text-xs text-gray-400" data-kbf-label></span>' +
                            '</div>' +
                            '<div class="flex items-center gap-2">' + themeButtons() + '</div>' +
                        '</div>' +
                        mobileLinks +
                        '<button type="button" class="kbf-mobile-link w-full text-left flex items-center gap-2" data-kbf-action="password">' + icon('key') + 'Change password</button>' +
                        '<a href="/admin/two-step" class="kbf-mobile-link flex items-center gap-2">' + icon('shield') + '2-step verification</a>' +
                        '<button type="button" class="w-full px-4 py-2 bg-slate-800 border-2 border-red-500 text-red-400 font-bold uppercase text-sm hover:bg-red-500 hover:text-slate-900 transition flex items-center gap-2" data-kbf-action="signout">' + icon('signout') + 'Sign out</button>' +
                    '</div>' +
                '</div>' +
            '</nav>';

        var host = document.getElementById('kbf-admin-header');
        if (!host) {
            host = document.createElement('div');
            host.id = 'kbf-admin-header';
            document.body.insertBefore(host, document.body.firstChild);
        }
        host.innerHTML = html;

        host.querySelectorAll('[data-kbf-name]').forEach(function (el) { el.textContent = user.name; });
        host.querySelectorAll('[data-kbf-label]').forEach(function (el) { el.textContent = user.mainLabel; });

        wireHeader(host);
    }

    function closeAllDropdowns(except) {
        document.querySelectorAll('[data-kbf-dropdown]').forEach(function (dd) {
            if (dd === except) return;
            var menu = dd.querySelector('[data-kbf-menu]');
            var toggle = dd.querySelector('[data-kbf-toggle]');
            if (menu) menu.classList.add('hidden');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    }

    function wireHeader(host) {
        host.querySelectorAll('[data-kbf-dropdown]').forEach(function (dd) {
            var toggle = dd.querySelector('[data-kbf-toggle]');
            var menu = dd.querySelector('[data-kbf-menu]');
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = menu.classList.contains('hidden');
                closeAllDropdowns(dd);
                menu.classList.toggle('hidden', !open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
        document.addEventListener('click', function () { closeAllDropdowns(null); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllDropdowns(null); });

        var mobileToggle = host.querySelector('[data-kbf-mobile-toggle]');
        var mobileMenu = host.querySelector('[data-kbf-mobile-menu]');
        mobileToggle.addEventListener('click', function () {
            var open = mobileMenu.classList.contains('hidden');
            mobileMenu.classList.toggle('hidden', !open);
            mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            mobileToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            host.querySelector('[data-kbf-icon-open]').classList.toggle('hidden', open);
            host.querySelector('[data-kbf-icon-close]').classList.toggle('hidden', !open);
        });

        host.querySelectorAll('.kbf-theme-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { applyTheme(btn.getAttribute('data-theme')); });
        });

        host.querySelectorAll('[data-kbf-action="password"]').forEach(function (btn) {
            btn.addEventListener('click', function () { closeAllDropdowns(null); openPasswordDialog(); });
        });
        host.querySelectorAll('[data-kbf-action="signout"]').forEach(function (btn) {
            btn.addEventListener('click', signOut);
        });
    }

    // ---------- impersonation banner ----------
    function renderImpersonationBanner(user) {
        var imp = user.impersonation;
        var existing = document.getElementById('kbf-impersonation-banner');
        if (existing) existing.remove();
        if (!imp) return;

        var banner = document.createElement('div');
        banner.id = 'kbf-impersonation-banner';
        banner.className = 'kbf-imp-banner';
        banner.setAttribute('role', 'status');
        banner.innerHTML =
            '<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex flex-col sm:flex-row sm:items-center gap-3">' +
                '<div class="flex items-center gap-3 flex-1 min-w-0">' +
                    '<span class="kbf-imp-icon flex items-center justify-center w-10 h-10 flex-shrink-0">' + icon('eye', 'w-6 h-6') + '</span>' +
                    '<div class="min-w-0">' +
                        '<p class="font-black uppercase text-sm sm:text-base leading-tight">You are viewing as <span data-kbf-imp-target></span></p>' +
                        '<p class="text-xs sm:text-sm font-semibold leading-snug">Everything you do is recorded as <span data-kbf-imp-admin></span> acting as this person. Password, email and access changes are blocked.</p>' +
                    '</div>' +
                '</div>' +
                '<button type="button" class="kbf-imp-btn px-4 py-2 font-black uppercase text-xs whitespace-nowrap self-start sm:self-auto" data-kbf-imp-stop>Return to my account</button>' +
            '</div>';

        banner.querySelector('[data-kbf-imp-target]').textContent = imp.targetName || user.name;
        banner.querySelector('[data-kbf-imp-admin]').textContent = imp.superAdminName || 'you';

        var host = document.getElementById('kbf-admin-header');
        host.parentNode.insertBefore(banner, host);

        var stopButton = banner.querySelector('[data-kbf-imp-stop]');
        stopButton.addEventListener('click', function () {
            stopButton.disabled = true;
            stopImpersonation().catch(function (message) {
                stopButton.disabled = false;
                window.alert(message);
            });
        });
    }

    // Returns to the Super Admin's own account, then to the person's staff page
    function stopImpersonation() {
        return fetch('/api/staff.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify({ action: 'stopImpersonation' })
        }).then(function (res) {
            return res.json().catch(function () { return { success: false }; });
        }).then(function (data) {
            if (data && data.success) {
                var url = (typeof data.redirectUrl === 'string' && /^\/admin\/[A-Za-z0-9\-]+(\?id=\d+)?$/.test(data.redirectUrl))
                    ? data.redirectUrl : '/admin/people?tab=staff';
                go(url);
                return;
            }
            // Session already returned to the Super Admin (e.g. after 30 minutes)
            go('/admin/dashboard');
        }, function () {
            throw 'Could not return to your account. Please try again.';
        });
    }

    // Upload limit in MB from Site settings (sent by session.php):
    // kind = photo, news, gallery, poster, sponsor or emailAttachments
    function uploadLimitMb(kind, fallbackMb) {
        var limits = window.KBFShell && window.KBFShell.uploadLimits;
        var n = limits ? parseInt(limits[kind], 10) : NaN;
        return n > 0 ? n : fallbackMb;
    }

    // Impersonation length from the Security page (sent by session.php)
    function impersonationMinutes() {
        var s = window.KBFShell && window.KBFShell.sessionTimes;
        var n = s ? parseInt(s.impersonationMinutes, 10) : NaN;
        return n > 0 ? n : 30;
    }

    // ---------- start impersonation (Staff pages) ----------
    function openImpersonateDialog(person) {
        if (!person || !person.userId) return;
        var existing = document.getElementById('kbf-impersonate-dialog');
        if (existing) existing.remove();

        // A Super Admin with 2-step on also gives a current code
        var needsCode = !!(window.KBFShell.user && window.KBFShell.user.twoStepEnabled);

        var wrap = document.createElement('div');
        wrap.id = 'kbf-impersonate-dialog';
        wrap.className = 'fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/70';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.setAttribute('aria-labelledby', 'kbf-imp-title');
        wrap.innerHTML =
            '<form class="w-full max-w-md bg-slate-900 border-4 border-gray-700 p-5 sm:p-6 space-y-4" novalidate>' +
                '<div class="flex items-start justify-between gap-4">' +
                    '<div class="flex items-center gap-3">' +
                        '<span class="kbf-imp-icon flex items-center justify-center w-9 h-9 flex-shrink-0">' + icon('eye', 'w-5 h-5') + '</span>' +
                        '<h2 id="kbf-imp-title" class="text-lg font-black uppercase text-white"></h2>' +
                    '</div>' +
                    '<button type="button" class="p-1 text-gray-400 hover:text-white" data-kbf-close aria-label="Close">' + icon('close', 'w-5 h-5') + '</button>' +
                '</div>' +
                '<p class="text-sm text-gray-300 font-semibold">You will see the site exactly as they do. Everything you do is recorded as you acting as them. Ends after ' + impersonationMinutes() + ' minutes or when you return to your account.</p>' +
                '<div>' +
                    '<label for="kbf-imp-password" class="block text-xs font-black uppercase mb-2" style="color:var(--theme-primary)">Your password</label>' +
                    '<input type="password" id="kbf-imp-password" class="kbf-input" autocomplete="current-password" required>' +
                '</div>' +
                (needsCode ?
                '<div>' +
                    '<label for="kbf-imp-code" class="block text-xs font-black uppercase mb-2" style="color:var(--theme-primary)">Code from your authenticator app</label>' +
                    '<input type="text" id="kbf-imp-code" class="kbf-input tracking-[0.4em] font-mono" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required>' +
                '</div>' : '') +
                '<div class="hidden p-3 border-2 border-red-500 bg-red-500/20 text-red-400 text-sm font-bold" data-kbf-message role="alert"></div>' +
                '<div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">' +
                    '<button type="button" class="px-4 py-2 bg-slate-800 border-2 border-gray-700 font-bold uppercase text-xs hover:border-gray-500" data-kbf-close>Cancel</button>' +
                    '<button type="submit" class="px-4 py-2 kbf-btn-theme font-black uppercase text-xs" data-kbf-submit>Start</button>' +
                '</div>' +
            '</form>';
        document.body.appendChild(wrap);
        wrap.querySelector('#kbf-imp-title').textContent = 'Impersonate ' + (person.name || 'this person') + '?';

        var form = wrap.querySelector('form');
        var message = wrap.querySelector('[data-kbf-message]');
        var submit = wrap.querySelector('[data-kbf-submit]');
        var password = wrap.querySelector('#kbf-imp-password');

        function close() { wrap.remove(); }
        function showError(text) {
            message.textContent = text;
            message.classList.remove('hidden');
        }

        wrap.querySelectorAll('[data-kbf-close]').forEach(function (b) { b.addEventListener('click', close); });
        wrap.addEventListener('click', function (e) { if (e.target === wrap) close(); });
        wrap.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!password.value) { showError('Please enter your password.'); return; }
            var codeInput = wrap.querySelector('#kbf-imp-code');
            var code = codeInput ? codeInput.value.replace(/\D+/g, '') : '';
            if (codeInput && code.length !== 6) { showError('Please enter the 6-digit code from your authenticator app.'); return; }
            submit.disabled = true;
            fetch('/api/staff.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({ action: 'startImpersonation', userId: Number(person.userId), password: password.value, code: code })
            }).then(function (res) {
                return res.json().catch(function () { return { success: false }; });
            }).then(function (data) {
                if (data && data.success) {
                    go('/admin/dashboard');
                    return;
                }
                password.value = '';
                if (codeInput) codeInput.value = '';
                showError((data && data.message) ? String(data.message) : 'Could not start. Please try again.');
                submit.disabled = false;
            }).catch(function () {
                showError('Could not start. Please try again.');
                submit.disabled = false;
            });
        });

        password.focus();
    }

    // ---------- sign out ----------
    function signOut() {
        fetch('/api/auth/logout.php', {
            method: 'GET',
            credentials: 'include',
            headers: { 'X-CSRF-Token': csrfToken() }
        }).catch(function () { /* ignore */ }).then(function () {
            try { sessionStorage.removeItem('csrf_token'); } catch (e) { /* ignore */ }
            go('/login');
        });
    }

    // ---------- change own password ----------
    function passwordProblem(password) {
        if (password.length < 8) return 'The new password must be at least 8 characters.';
        if (!/[A-Z]/.test(password)) return 'The new password needs at least one uppercase letter.';
        if (!/[a-z]/.test(password)) return 'The new password needs at least one lowercase letter.';
        if (!/[0-9]/.test(password)) return 'The new password needs at least one number.';
        return null;
    }

    function openPasswordDialog() {
        var existing = document.getElementById('kbf-password-dialog');
        if (existing) existing.remove();

        var wrap = document.createElement('div');
        wrap.id = 'kbf-password-dialog';
        wrap.className = 'fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/70';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.setAttribute('aria-labelledby', 'kbf-password-title');
        wrap.innerHTML =
            '<form class="w-full max-w-md bg-slate-900 border-4 border-gray-700 p-5 sm:p-6 space-y-4" novalidate>' +
                '<div class="flex items-start justify-between gap-4">' +
                    '<h2 id="kbf-password-title" class="text-lg font-black uppercase text-white">Change password</h2>' +
                    '<button type="button" class="p-1 text-gray-400 hover:text-white" data-kbf-close aria-label="Close">' + icon('close', 'w-5 h-5') + '</button>' +
                '</div>' +
                '<p class="text-xs text-gray-400 font-semibold">At least 8 characters, with an uppercase letter, a lowercase letter and a number.</p>' +
                '<div>' +
                    '<label for="kbf-pw-current" class="block text-xs font-black uppercase mb-2" style="color:var(--theme-primary)">Current password</label>' +
                    '<input type="password" id="kbf-pw-current" class="kbf-input" autocomplete="current-password" required>' +
                '</div>' +
                '<div>' +
                    '<label for="kbf-pw-new" class="block text-xs font-black uppercase mb-2" style="color:var(--theme-primary)">New password</label>' +
                    '<input type="password" id="kbf-pw-new" class="kbf-input" autocomplete="new-password" required>' +
                '</div>' +
                '<div>' +
                    '<label for="kbf-pw-confirm" class="block text-xs font-black uppercase mb-2" style="color:var(--theme-primary)">Confirm new password</label>' +
                    '<input type="password" id="kbf-pw-confirm" class="kbf-input" autocomplete="new-password" required>' +
                '</div>' +
                '<div class="hidden p-3 border-2 text-sm font-bold" data-kbf-message role="alert"></div>' +
                '<div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">' +
                    '<button type="button" class="px-4 py-2 bg-slate-800 border-2 border-gray-700 font-bold uppercase text-xs hover:border-gray-500" data-kbf-close>Cancel</button>' +
                    '<button type="submit" class="px-4 py-2 kbf-btn-theme font-black uppercase text-xs" data-kbf-submit>Change password</button>' +
                '</div>' +
            '</form>';
        document.body.appendChild(wrap);

        var form = wrap.querySelector('form');
        var message = wrap.querySelector('[data-kbf-message]');
        var submit = wrap.querySelector('[data-kbf-submit]');

        function close() { wrap.remove(); }
        function show(text, ok) {
            message.textContent = text;
            message.className = 'p-3 border-2 text-sm font-bold ' + (ok
                ? 'border-green-500 bg-green-500/20 text-green-400'
                : 'border-red-500 bg-red-500/20 text-red-400');
        }

        wrap.querySelectorAll('[data-kbf-close]').forEach(function (b) { b.addEventListener('click', close); });
        wrap.addEventListener('click', function (e) { if (e.target === wrap) close(); });
        wrap.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var current = wrap.querySelector('#kbf-pw-current').value;
            var next = wrap.querySelector('#kbf-pw-new').value;
            var confirm = wrap.querySelector('#kbf-pw-confirm').value;

            if (!current) { show('Please enter your current password.', false); return; }
            var problem = passwordProblem(next);
            if (problem) { show(problem, false); return; }
            if (next !== confirm) { show('The new passwords do not match.', false); return; }
            if (next === current) { show('The new password must be different from the current one.', false); return; }

            submit.disabled = true;
            fetch('/api/auth/change-password.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({ currentPassword: current, newPassword: next })
            }).then(function (res) {
                return res.json().catch(function () { return { success: false }; });
            }).then(function (data) {
                if (data && data.success) {
                    form.reset();
                    show('Your password has been changed.', true);
                    setTimeout(close, 1500);
                } else {
                    show((data && data.message) ? String(data.message) : 'Your password could not be changed.', false);
                }
            }).catch(function () {
                show('Your password could not be changed. Please try again.', false);
            }).then(function () {
                submit.disabled = false;
            });
        });

        wrap.querySelector('#kbf-pw-current').focus();
    }

    // ---------- guard ----------
    function start(options) {
        var opts = options || {};
        var anyOf = Array.isArray(opts.anyOf) ? opts.anyOf : [];
        document.documentElement.style.visibility = 'hidden';

        return new Promise(function (resolve) {
            fetch('/api/auth/session.php', { method: 'GET', credentials: 'include' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.success || !data.loggedIn) {
                        if (data && data.mustChangePassword) { go('/change-password'); return; }
                        if (data && data.twoStepPending) {
                            go(data.twoStepPending.mode === 'setup' ? '/admin/two-step' : '/two-step');
                            return;
                        }
                        go('/login');
                        return;
                    }
                    if (data.mustChangePassword) { go('/change-password'); return; }

                    if (data.csrf_token) {
                        try { sessionStorage.setItem('csrf_token', data.csrf_token); } catch (e) { /* ignore */ }
                    }

                    var user = buildUser(data.user || {});
                    if (data.impersonation && data.impersonation.active) {
                        user.impersonation = data.impersonation;
                        if (data.impersonation.targetLabel) user.mainLabel = String(data.impersonation.targetLabel);
                    }
                    var allowed = opts.superAdminOnly ? user.isSuperAdmin : user.hasAny(anyOf);
                    if (!allowed) { go('/admin/dashboard'); return; }

                    window.KBFShell.user = user;
                    window.KBFShell.sessionTimes = data.session || null;
                    window.KBFShell.uploadLimits = data.uploadLimitsMb || null;
                    injectStyles();
                    renderHeader(user, opts.active || '');
                    renderImpersonationBanner(user);
                    if (opts.controlSection) renderControlNav(user, opts.controlSection);
                    var saved = 'emerald';
                    try { saved = localStorage.getItem('badminton-theme') || 'emerald'; } catch (e) { /* ignore */ }
                    applyTheme(saved);

                    document.documentElement.style.visibility = 'visible';
                    resolve(user);
                })
                .catch(function () {
                    go('/login');
                });
        });
    }

    // Start a control centre page: the guard follows the section's access,
    // the header highlights Settings and the sidebar is drawn.
    //   KBFShell.startControl({ section: 'email' }).then(function (user) { ... });
    function startControl(options) {
        var key = (options && options.section) || '';
        var section = null;
        CONTROL_SECTIONS.forEach(function (s) { if (s.key === key) section = s; });
        var guard = (!section || section.superAdminOnly)
            ? { superAdminOnly: true }
            : { anyOf: section.anyOf };
        guard.active = 'settings';
        guard.controlSection = key;
        return start(guard);
    }

    window.KBFShell = {
        start: start,
        startControl: startControl,
        impersonationMinutes: impersonationMinutes,
        uploadLimitMb: uploadLimitMb,
        user: null,
        escapeHtml: escapeHtml,
        PERMISSION_LABELS: PERMISSION_LABELS,
        PERMISSION_ORDER: PERMISSION_ORDER,
        signOut: signOut,
        openPasswordDialog: openPasswordDialog,
        openImpersonateDialog: openImpersonateDialog,
        stopImpersonation: stopImpersonation
    };
})();
