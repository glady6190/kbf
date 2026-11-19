/* ============================================
 * KBF-UK ADMIN SHELL
 * One shared header and page guard for every page in admin/.
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
        'control.grading': 'Grading rules and seasons',
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
        { key: 'grading', label: 'Grading rules and seasons', href: '/admin/grading-settings', anyOf: ['control.grading'], icon: 'chart' },
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
        // Organisers (organise_own / own_news_gallery) see only their own
        // tournaments and posts on these pages
        { key: 'tournaments', label: 'Tournaments', href: '/admin/tournaments', anyOf: ['tournaments.approve', 'tournaments.manage_all', 'tournaments.organise_own'] },
        { key: 'grading', label: 'Player grades', href: '/admin/rankings', anyOf: ['players.change_grades'] },
        { key: 'news', label: 'News', href: '/admin/announcements', anyOf: ['content.all_news_gallery', 'content.own_news_gallery'] },
        { key: 'broadcasts', label: 'Broadcasts', href: '/admin/broadcasts', anyOf: ['content.broadcasts'] },
        { key: 'sponsors', label: 'Sponsors', href: '/admin/sponsors', anyOf: ['content.sponsors'] },
        { key: 'staff', label: 'Staff', href: '/admin/people?tab=staff', superAdminOnly: true },
        // Opens the first control centre section the person can use
        { key: 'settings', label: 'Settings', href: '/admin/security', controlCentre: true }
    ];
    // Menu items in the header bar: as many as fit (fitHeaderMenu), the rest
    // go under "More"

    // Same colours as --theme-primary in each page's theme styles
    var THEMES = [
        { key: 'emerald', label: 'Emerald', colour: '#10b981' },
        { key: 'cyan', label: 'Cyan', colour: '#06b6d4' },
        { key: 'yellow', label: 'Yellow', colour: '#f4f916' },
        { key: 'violet', label: 'Violet', colour: '#A78BFA' }
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
        users: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        // Phone menu (js/admin-list-tools.js uses the same style)
        inbox: 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4',
        trophy: 'M8 21h8m-4-4v4m-5-17h10v5a5 5 0 01-10 0V4zM7 6H4v1a3 3 0 003 3m10-4h3v1a3 3 0 01-3 3',
        trend: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
        news: 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z',
        star: 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
        external: 'M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14'
    };

    // Phone menu groups (below 1024px): MENU keys per group, with an icon each
    var PHONE_GROUPS = [
        { label: 'Daily work', keys: ['dashboard', 'people', 'approvals', 'tournaments', 'grading'] },
        { label: 'Content', keys: ['news', 'broadcasts', 'sponsors'] },
        { label: 'Administration', keys: ['staff', 'settings'] }
    ];
    var PHONE_ICONS = {
        dashboard: 'grid', people: 'users', approvals: 'inbox', tournaments: 'trophy', grading: 'trend',
        news: 'news', broadcasts: 'mail', sponsors: 'star', staff: 'shield', settings: 'cog'
    };
    var PHONE_LABELS = { staff: 'Staff and access' };

    // ---------- helpers ----------
    // Shared escaper from /js/escape.js (must be loaded before this file).
    if (!window.KBF || typeof window.KBF.escapeHtml !== 'function') {
        throw new Error('admin-shell.js needs /js/escape.js to be loaded first');
    }
    var escapeHtml = window.KBF.escapeHtml;

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
            '.kbf-nav-link{display:inline-flex;align-items:center;min-height:44px;padding:.5rem .75rem;color:#9ca3af;font-weight:700;text-transform:uppercase;font-size:.75rem;white-space:nowrap;transition:color .2s ease;}' +
            '.kbf-nav-link:hover{color:#fff;}' +
            '.kbf-nav-link.kbf-active{background:var(--theme-primary);color:#0F172A;font-weight:900;}' +
            '.kbf-drop{position:absolute;right:0;top:100%;margin-top:.5rem;min-width:13rem;background:#0f172a;border:2px solid #374151;z-index:60;box-shadow:6px 6px 0 var(--theme-primary);}' +
            '.kbf-drop a,.kbf-drop button{display:flex;align-items:center;gap:.5rem;width:100%;min-height:44px;text-align:left;padding:.625rem .875rem;color:#d1d5db;font-weight:700;text-transform:uppercase;font-size:.75rem;}' +
            '.kbf-drop a:hover,.kbf-drop button:hover{background:#1e293b;color:#fff;}' +
            '.kbf-drop a.kbf-active{background:var(--theme-primary);color:#0F172A;}' +
            // Theme dots: 24px to look at, 44px to tap
            '.kbf-theme-btn{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;background:transparent;border:0;padding:0;cursor:pointer;}' +
            '.kbf-theme-dot{display:block;width:24px;height:24px;border-radius:50%;border:2px solid transparent;transition:transform .2s ease;}' +
            '.kbf-theme-btn:hover .kbf-theme-dot{transform:scale(1.1);border-color:#fff;}' +
            '.kbf-theme-btn.kbf-active .kbf-theme-dot{border-color:#fff;box-shadow:0 0 0 2px #0F172A,0 0 8px rgba(255,255,255,.6);}' +
            '.kbf-theme-btn:focus-visible{outline:2px solid #fff;outline-offset:-4px;}' +
            // Round logo with the theme-colour ring and soft glow, as on the public header
            '.kbf-logo{position:relative;display:block;width:44px;height:44px;flex-shrink:0;}' +
            '@media (min-width:640px){.kbf-logo{width:48px;height:48px;}}' +
            '.kbf-logo-glow{position:absolute;inset:0;border-radius:9999px;background:var(--theme-primary);filter:blur(10px);opacity:.4;}' +
            '.kbf-logo-ring{position:relative;display:block;width:100%;height:100%;border-radius:9999px;border:3px solid var(--theme-primary);box-shadow:0 0 12px var(--theme-primary);background:#fff;overflow:hidden;}' +
            '.kbf-logo-ring img{display:block;width:100%;height:100%;object-fit:contain;}' +
            // The bar never wraps or overlaps: fitHeaderMenu() moves items under More
            '.kbf-bar{flex-wrap:nowrap;}' +
            '.kbf-bar > *{flex-shrink:0;}' +
            // "KBF-UK Admin" text only from 1280px on desktop (the logo stays);
            // phones and tablets (no bar) keep it
            '@media (min-width:1024px) and (max-width:1279.98px){.kbf-brand-text{display:none;}}' +
            // The menu (hamburger) and control-centre section buttons only
            // where the full menu or the sidebar is hidden (the shell's own
            // display rules would otherwise beat lg:hidden)
            '@media (min-width:1024px){[data-kbf-mobile-toggle],[data-kbf-cc-toggle]{display:none !important;}}' +
            '.kbf-drop-themes{display:flex;align-items:center;gap:.25rem;padding:.25rem .5rem .25rem .875rem;border-bottom:2px solid #374151;}' +
            '.kbf-drop-themes-label{flex:1;color:#9ca3af;font-weight:700;text-transform:uppercase;font-size:.75rem;}' +
            '.kbf-drop .kbf-theme-btn{width:44px;padding:0;justify-content:center;}' +
            '.kbf-drop .kbf-theme-btn:hover{background:transparent;}' +
            '.kbf-icon-btn{display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;}' +
            // Visible keyboard focus on shell links and buttons
            '.kbf-nav-link:focus-visible,.kbf-drop a:focus-visible,.kbf-drop button:focus-visible,.kbf-mobile-link:focus-visible,.kbf-cc-link:focus-visible,.kbf-cc-toggle:focus-visible,.kbf-icon-btn:focus-visible{outline:2px solid #fff;outline-offset:2px;}' +
            // "Could not be loaded" message with Try again (KBF.showLoadError)
            '.kbf-load-error{padding:2rem 1rem;text-align:center;}' +
            '.kbf-load-error-text{color:#f87171;font-weight:700;font-size:.875rem;margin-bottom:1rem;}' +
            '.kbf-load-error-btn{min-height:44px;padding:.5rem 1.25rem;background:var(--theme-primary);color:#0F172A;border:2px solid var(--theme-primary);font-weight:900;text-transform:uppercase;font-size:.75rem;}' +
            '.kbf-load-error-btn:disabled{opacity:.6;cursor:wait;}' +
            '.kbf-load-error-btn:focus-visible{outline:2px solid #fff;outline-offset:2px;}' +
            // Footer
            '.kbf-footer{border-top:4px solid #1f2937;margin-top:3rem;}' +
            '.kbf-footer a{color:#9ca3af;font-weight:700;text-transform:uppercase;font-size:.75rem;display:inline-flex;align-items:center;min-height:44px;}' +
            '.kbf-footer a:hover{color:#fff;}' +
            '.kbf-mobile-link{display:block;min-height:44px;padding:.75rem 1rem;background:#1e293b;color:#d1d5db;font-weight:700;text-transform:uppercase;font-size:.875rem;}' +
            '.kbf-mobile-link:hover{color:#fff;}' +
            '.kbf-mobile-link.kbf-active{background:var(--theme-primary);color:#0F172A;font-weight:900;}' +
            '.kbf-input{width:100%;background:#1e293b;border:2px solid #374151;color:#fff;padding:.625rem .875rem;font-weight:700;font-size:.875rem;}' +
            '.kbf-input:focus{outline:none;border-color:var(--theme-primary);}' +
            // Phones: 16px so iOS does not zoom in on the field
            '@media (max-width:639.98px){.kbf-input{font-size:16px;}}' +
            '.kbf-btn-theme{background:var(--theme-primary);color:#0F172A;}' +
            '#kbf-impersonate-dialog button,#kbf-password-dialog button,.kbf-imp-btn{min-height:44px;}' +
            // Impersonation banner: fixed amber colours on every theme
            '.kbf-imp-banner{background:repeating-linear-gradient(135deg,#F59E0B 0,#F59E0B 18px,#FBBF24 18px,#FBBF24 36px);color:#0F172A;}' +
            '.kbf-imp-icon{background:#0F172A;color:#FBBF24;}' +
            '.kbf-imp-btn{background:#0F172A;color:#FFFFFF;border:2px solid #0F172A;}' +
            '.kbf-imp-btn:hover{background:#1e293b;}' +
            '.kbf-imp-btn:disabled{opacity:.6;cursor:wait;}' +
            // Control centre sidebar
            '.kbf-cc-link{display:flex;align-items:center;gap:.625rem;min-height:44px;padding:.625rem .75rem;color:#9ca3af;font-weight:700;text-transform:uppercase;font-size:.75rem;transition:color .2s ease,background-color .2s ease;}' +
            '.kbf-cc-link:hover{color:#fff;background:#1e293b;}' +
            '.kbf-cc-link.kbf-active{background:var(--theme-primary);color:#0F172A;font-weight:900;}' +
            '.kbf-cc-toggle{display:flex;align-items:center;justify-content:space-between;gap:.75rem;width:100%;min-height:44px;padding:.75rem 1rem;background:#0f172a;border:2px solid #374151;color:#fff;font-weight:900;text-transform:uppercase;font-size:.75rem;}' +
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
            if (btn.classList.contains('kbf-theme-btn')) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function themeButtons() {
        return THEMES.map(function (t) {
            return '<button type="button" class="kbf-theme-btn" data-theme="' + t.key + '" aria-label="' + t.label + ' theme" aria-pressed="false">' +
                '<span class="kbf-theme-dot" style="background:' + t.colour + '" aria-hidden="true"></span></button>';
        }).join('');
    }

    // ---------- header ----------
    function renderHeader(user, active) {
        var items = visibleMenu(user);
        // Every item is in the bar and in "More"; fitHeaderMenu() shows as
        // many as fit in the bar at the current width and the rest under
        // "More", so the logo, title, menu and account button never overlap
        var bar = items.map(function (i, n) {
            var cls = 'kbf-nav-link' + (i.key === active ? ' kbf-active' : '');
            return '<a href="' + i.href + '" class="' + cls + '" data-kbf-bar-item="' + n + '"' + (i.key === active ? ' aria-current="page"' : '') + '>' + escapeHtml(i.label) + '</a>';
        }).join('');

        var more = items.length ?
            '<div class="relative hidden" data-kbf-dropdown data-kbf-more-wrap>' +
                '<button type="button" class="kbf-nav-link flex items-center gap-1" data-kbf-toggle data-kbf-more-btn aria-haspopup="true" aria-expanded="false">More ' + icon('chevron', 'w-3 h-3') + '</button>' +
                '<div class="kbf-drop hidden" data-kbf-menu>' +
                    items.map(function (i, n) {
                        var cls = i.key === active ? ' class="kbf-active"' : '';
                        return '<a href="' + i.href + '"' + cls + ' data-kbf-more-item="' + n + '"' + (i.key === active ? ' aria-current="page"' : '') + '>' + escapeHtml(i.label) + '</a>';
                    }).join('') +
                '</div>' +
            '</div>' : '';

        var account =
            '<div class="relative" data-kbf-dropdown>' +
                '<button type="button" class="kbf-account-btn flex items-center gap-2 px-3 py-2 bg-slate-800 border-2 border-gray-700 hover:border-gray-500 transition" style="min-height:44px" data-kbf-toggle aria-haspopup="true" aria-expanded="false">' +
                    '<span class="theme-primary-text" style="color:var(--theme-primary)">' + icon('user', 'w-4 h-4') + '</span>' +
                    '<span class="text-left leading-tight">' +
                        '<span class="block font-bold uppercase text-xs text-white max-w-[10rem] truncate" data-kbf-name></span>' +
                        '<span class="block font-bold uppercase text-[10px] text-gray-400" data-kbf-label></span>' +
                    '</span>' +
                    icon('chevron', 'w-3 h-3') +
                '</button>' +
                '<div class="kbf-drop hidden" data-kbf-menu>' +
                    '<div class="kbf-drop-themes" role="group" aria-label="Colour theme">' +
                        '<span class="kbf-drop-themes-label">Theme</span>' + themeButtons() +
                    '</div>' +
                    '<button type="button" data-kbf-action="password">' + icon('key') + 'Change password</button>' +
                    '<a href="/admin/two-step">' + icon('shield') + '2-step verification</a>' +
                    '<button type="button" data-kbf-action="signout-everywhere">' + icon('signout') + 'Sign out everywhere</button>' +
                    '<button type="button" data-kbf-action="signout">' + icon('signout') + 'Sign out</button>' +
                '</div>' +
            '</div>';

        // Phones: the pages in groups, each with an icon; a group with none
        // of the person's pages is left out. Counts fill the badges later.
        var byKey = {};
        items.forEach(function (i) { byKey[i.key] = i; });
        var mobileGroups = PHONE_GROUPS.map(function (g, n) {
            var links = g.keys.filter(function (k) { return byKey[k]; }).map(function (k) {
                var i = byKey[k];
                var on = k === active;
                var badge = k === 'approvals' ? '<span class="kbf-pm-badge kbf-pm-badge-red hidden" data-kbf-count="approvals"></span>'
                    : (k === 'tournaments' ? '<span class="kbf-pm-badge kbf-pm-badge-amber hidden" data-kbf-count="resultsDue"></span>' : '');
                return '<li><a href="' + i.href + '" class="kbf-pm-link' + (on ? ' kbf-active' : '') + '"' + (on ? ' aria-current="page"' : '') + '>' +
                    icon(PHONE_ICONS[k] || 'grid', 'kbf-pm-icon') + '<span class="kbf-pm-label">' + escapeHtml(PHONE_LABELS[k] || i.label) + '</span>' + badge + '</a></li>';
            }).join('');
            if (!links) return '';
            return '<h2 class="kbf-pm-group" id="kbf-pm-group-' + n + '">' + escapeHtml(g.label) + '</h2>' +
                '<ul aria-labelledby="kbf-pm-group-' + n + '">' + links + '</ul>';
        }).join('');
        var canApprove = !!byKey.approvals;
        var peopleLink = byKey.people ? '/admin/people?person=' + encodeURIComponent(String(user.id)) : '';

        var html =
            '<nav class="bg-slate-900 border-b-4 theme-primary-border shadow-2xl" style="border-color:var(--theme-primary)">' +
                '<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">' +
                    '<div class="flex justify-between items-center h-16 sm:h-20 gap-4">' +
                        '<a href="/admin/dashboard" class="flex items-center gap-3 min-w-0 flex-shrink-0" aria-label="KBF-UK Admin dashboard">' +
                            '<span class="kbf-logo" aria-hidden="true"><span class="kbf-logo-glow"></span>' +
                                '<span class="kbf-logo-ring"><img src="/assets/images/logo/KBF-Logo.jpeg" alt=""></span></span>' +
                            '<span class="kbf-brand-text text-base sm:text-xl font-black uppercase whitespace-nowrap">KBF-UK <span class="kbf-brand-admin">Admin</span></span>' +
                        '</a>' +
                        '<div class="hidden lg:flex items-center gap-1 flex-1 justify-center min-w-0 kbf-bar" data-kbf-bar>' + bar + more + '</div>' +
                        '<div class="hidden lg:flex items-center gap-3 flex-shrink-0">' +
                            account +
                        '</div>' +
                        '<div class="lg:hidden flex items-center gap-2 flex-shrink-0">' +
                            // Approvals with the number waiting (people who can approve; hidden at 0)
                            (canApprove ?
                            '<a href="/admin/approvals" class="kbf-pm-top-btn hidden" data-kbf-approvals-btn aria-label="Approvals">' +
                                icon('inbox', 'w-6 h-6') + '<span class="kbf-pm-top-badge" data-kbf-count="approvals" aria-hidden="true"></span>' +
                            '</a>' : '') +
                            '<button type="button" class="kbf-pm-toggle text-gray-200" data-kbf-mobile-toggle aria-label="Open menu" aria-expanded="false" aria-controls="kbf-mobile-menu">' +
                                '<span data-kbf-icon-open>' + icon('menu', 'w-6 h-6') + '</span>' +
                                '<span data-kbf-icon-close class="hidden">' + icon('close', 'w-6 h-6') + '</span>' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                // Phones: the full-screen menu. Account card at the top, the
                // pages in groups (scrolling), and a fixed footer
                '<div id="kbf-mobile-menu" class="kbf-pm lg:hidden hidden" data-kbf-mobile-menu>' +
                    '<div class="kbf-pm-account">' +
                        '<span class="kbf-pm-avatar" aria-hidden="true" data-kbf-initials></span>' +
                        '<span class="min-w-0">' +
                            '<span class="kbf-pm-name" data-kbf-name></span>' +
                            '<span class="kbf-pm-role" data-kbf-label></span>' +
                        '</span>' +
                    '</div>' +
                    '<nav class="kbf-pm-list" aria-label="Admin pages">' + mobileGroups + '</nav>' +
                    '<div class="kbf-pm-foot">' +
                        '<div class="kbf-pm-theme" role="group" aria-label="Colour theme">' +
                            '<span class="kbf-pm-theme-label" aria-hidden="true">Theme</span>' +
                            '<span class="flex items-center">' + themeButtons() + '</span>' +
                        '</div>' +
                        '<div class="kbf-pm-actions">' +
                            '<a href="/" class="kbf-pm-btn">' + icon('external', 'w-4 h-4 flex-shrink-0') + 'View website</a>' +
                            '<button type="button" class="kbf-pm-btn kbf-pm-btn-danger" data-kbf-action="signout">' + icon('signout', 'w-4 h-4 flex-shrink-0') + 'Sign out</button>' +
                        '</div>' +
                        '<div class="kbf-pm-small">' +
                            (peopleLink ? '<a href="' + peopleLink + '">My account</a>' : '') +
                            '<button type="button" data-kbf-action="password">Change password</button>' +
                            '<a href="/admin/two-step">2-step verification</a>' +
                            '<button type="button" data-kbf-action="signout-everywhere">Sign out everywhere</button>' +
                        '</div>' +
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
        host.querySelectorAll('[data-kbf-initials]').forEach(function (el) { el.textContent = initialsOf(user.name); });

        wireHeader(host);
        loadCounts(host);

        // Fit the menu to the space now, and again whenever it changes
        fitHeaderMenu(host);
        if (!window.__kbfFitBound) {
            window.__kbfFitBound = true;
            var fitTimer = null;
            window.addEventListener('resize', function () {
                clearTimeout(fitTimer);
                fitTimer = setTimeout(function () { fitHeaderMenu(document.getElementById('kbf-admin-header')); }, 100);
            });
            window.addEventListener('load', function () { fitHeaderMenu(document.getElementById('kbf-admin-header')); });
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(function () { fitHeaderMenu(document.getElementById('kbf-admin-header')); });
            }
        }
    }

    // Show as many menu items in the header bar as fit beside the logo, title
    // and account button; the rest go under "More" (the item for the current
    // page is never hidden in both places). Below 1024px the bar is hidden
    // and the phone menu is used instead.
    function fitHeaderMenu(host) {
        if (!host) return;
        var bar = host.querySelector('[data-kbf-bar]');
        if (!bar) return;
        var barItems = Array.prototype.slice.call(bar.querySelectorAll('[data-kbf-bar-item]'));
        var moreWrap = bar.querySelector('[data-kbf-more-wrap]');
        var moreItems = Array.prototype.slice.call(bar.querySelectorAll('[data-kbf-more-item]'));
        var moreBtn = bar.querySelector('[data-kbf-more-btn]');

        // Start with everything in the bar
        barItems.forEach(function (a) { a.style.display = ''; });
        moreItems.forEach(function (a) { a.style.display = 'none'; });
        if (moreWrap) moreWrap.classList.add('hidden');
        if (moreBtn) moreBtn.classList.remove('kbf-active');
        if (window.getComputedStyle(bar).display === 'none') return;

        // Move the last items under More until the row fits
        var visible = barItems.length;
        // Total width of the shown items (and gaps) against the bar's width;
        // measured directly, because a centred row that overflows spills out
        // on both sides
        var fits = function () {
            var gap = parseFloat(window.getComputedStyle(bar).columnGap) || 0;
            var total = 0;
            var shown = 0;
            Array.prototype.forEach.call(bar.children, function (child) {
                if (child.offsetParent === null && window.getComputedStyle(child).display === 'none') return;
                if (child.classList.contains('hidden')) return;
                total += child.getBoundingClientRect().width;
                shown++;
            });
            total += gap * Math.max(0, shown - 1);
            return total <= bar.clientWidth + 1;
        };
        if (fits()) return;
        if (moreWrap) moreWrap.classList.remove('hidden');
        while (visible > 0 && !fits()) {
            visible--;
            barItems[visible].style.display = 'none';
            if (moreItems[visible]) moreItems[visible].style.display = '';
        }
        // "More" shows as current when the current page is inside it
        var activeHidden = barItems.some(function (a, n) { return n >= visible && a.classList.contains('kbf-active'); });
        if (moreBtn && activeHidden) moreBtn.classList.add('kbf-active');
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

        var mobileToggle = host.querySelector('[data-kbf-mobile-toggle]');
        var mobileMenu = host.querySelector('[data-kbf-mobile-menu]');
        var navEl = host.querySelector('nav');
        var menuInerted = [];
        // Phones: the menu fills the screen (the top bar stays, its menu
        // button becomes a close button). The page behind is made inert
        // (inert only) and does not scroll.
        function setMobileMenu(open) {
            mobileMenu.classList.toggle('hidden', !open);
            navEl.classList.toggle('kbf-pm-open', open);
            mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            mobileToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            host.querySelector('[data-kbf-icon-open]').classList.toggle('hidden', open);
            host.querySelector('[data-kbf-icon-close]').classList.toggle('hidden', !open);
            menuInerted.forEach(function (n) { n.removeAttribute('inert'); });
            menuInerted = [];
            if (open) {
                Array.prototype.forEach.call(document.body.children, function (n) {
                    if (n === host || n.inert || n.hasAttribute('inert')) return;
                    if (/^(SCRIPT|STYLE|LINK|TEMPLATE)$/.test(n.tagName) || n.hasAttribute('data-kbf-live')) return;
                    n.setAttribute('inert', '');
                    menuInerted.push(n);
                });
                document.documentElement.classList.add('kbf-pm-lock');
            } else {
                document.documentElement.classList.remove('kbf-pm-lock');
            }
        }
        mobileToggle.addEventListener('click', function () {
            setMobileMenu(mobileMenu.classList.contains('hidden'));
        });
        // Wider than a phone: the full menu is in the bar again
        try {
            window.matchMedia('(min-width: 1024px)').addEventListener('change', function (e) {
                if (e.matches && !mobileMenu.classList.contains('hidden')) setMobileMenu(false);
            });
        } catch (e) { /* older browsers */ }
        // A page link or action closes the menu first
        mobileMenu.addEventListener('click', function (e) {
            if (e.target.closest('[data-kbf-action="password"], [data-kbf-action="signout-everywhere"]')) setMobileMenu(false);
        });

        // Escape closes an open drop-down or the phone menu and puts focus
        // back on the button that opened it
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || e.defaultPrevented) return;
            var openToggle = null;
            host.querySelectorAll('[data-kbf-dropdown]').forEach(function (dd) {
                var menu = dd.querySelector('[data-kbf-menu]');
                if (menu && !menu.classList.contains('hidden')) openToggle = dd.querySelector('[data-kbf-toggle]');
            });
            if (openToggle) {
                closeAllDropdowns(null);
                openToggle.focus();
                return;
            }
            if (!mobileMenu.classList.contains('hidden')) {
                setMobileMenu(false);
                mobileToggle.focus();
            }
        });

        host.querySelectorAll('.kbf-theme-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                // Keep the account menu open while trying themes
                e.stopPropagation();
                applyTheme(btn.getAttribute('data-theme'));
            });
        });

        // The account button: where focus returns after a dialog opened from
        // its menu (the menu item itself is hidden by then)
        function accountToggleFor(btn) {
            var dd = btn.closest('[data-kbf-dropdown]');
            if (dd) return dd.querySelector('[data-kbf-toggle]');
            if (btn.closest('[data-kbf-mobile-menu]')) return mobileToggle;
            return btn;
        }
        host.querySelectorAll('[data-kbf-action="password"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeAllDropdowns(null);
                openPasswordDialog(accountToggleFor(btn));
            });
        });
        host.querySelectorAll('[data-kbf-action="signout"]').forEach(function (btn) {
            btn.addEventListener('click', signOut);
        });
        host.querySelectorAll('[data-kbf-action="signout-everywhere"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeAllDropdowns(null);
                signOutEverywhere(accountToggleFor(btn));
            });
        });
    }

    function initialsOf(name) {
        var words = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!words.length) return '?';
        return (words[0].charAt(0) + (words.length > 1 ? words[words.length - 1].charAt(0) : '')).toUpperCase();
    }

    // ---------- counts for the phone top bar and menu ----------
    // One small request (api/admin-counts.php) gives only the counts this
    // person may see; kept for 60 seconds in this tab so moving between
    // pages does not ask again.
    var COUNTS_KEY = 'kbf-admin-counts';
    function loadCounts(host) {
        if (!host.querySelector('[data-kbf-count]')) return;
        var store = window.KBF && window.KBF.storage ? window.KBF.storage.session : null;
        var userId = window.KBFShell.user ? String(window.KBFShell.user.id) : '';
        var cached = null;
        try { cached = JSON.parse(store ? store.get(COUNTS_KEY, '') || 'null' : 'null'); } catch (e) { cached = null; }
        if (cached && cached.user === userId && Date.now() - cached.at < 60000 && cached.counts) {
            showCounts(host, cached.counts);
            return;
        }
        fetch('/api/admin-counts.php', { credentials: 'include' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.counts) return;
                if (store) store.set(COUNTS_KEY, JSON.stringify({ user: userId, at: Date.now(), counts: data.counts }));
                showCounts(host, data.counts);
            })
            .catch(function () { /* the badges simply stay hidden */ });
    }

    function showCounts(host, counts) {
        host.querySelectorAll('[data-kbf-count]').forEach(function (el) {
            var key = el.getAttribute('data-kbf-count');
            var n = parseInt(counts[key], 10) || 0;
            el.textContent = n > 99 ? '99+' : String(n);
            // Menu links read "Approvals 47 waiting" to screen readers
            if (el.closest('.kbf-pm-link')) {
                var extra = document.createElement('span');
                extra.className = 'sr-only';
                extra.textContent = key === 'resultsDue' ? ' with results due' : ' waiting';
                el.appendChild(extra);
            }
            el.classList.toggle('hidden', n < 1);
        });
        var n = parseInt(counts.approvals, 10) || 0;
        var btn = host.querySelector('[data-kbf-approvals-btn]');
        if (btn) {
            btn.classList.toggle('hidden', n < 1);
            btn.setAttribute('aria-label', 'Approvals, ' + n + ' waiting');
        }
    }

    // ---------- footer ----------
    // The same small footer on every staff page
    function renderFooter() {
        if (document.getElementById('kbf-admin-footer')) return;
        var footer = document.createElement('footer');
        footer.id = 'kbf-admin-footer';
        footer.className = 'kbf-footer';
        footer.innerHTML =
            '<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">' +
                '<p class="text-xs font-bold uppercase text-gray-500">KBF-UK staff area</p>' +
                '<nav aria-label="Footer" class="flex flex-wrap gap-x-6">' +
                    '<a href="/">View the public site</a>' +
                    '<a href="/terms">Terms and privacy</a>' +
                '</nav>' +
            '</div>';
        // After the page's own content, before its scripts
        var firstScript = null;
        for (var i = 0; i < document.body.children.length; i++) {
            var child = document.body.children[i];
            if (child.tagName === 'SCRIPT' && i > 0) { firstScript = child; break; }
        }
        if (firstScript) document.body.insertBefore(footer, firstScript);
        else document.body.appendChild(footer);
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
                failNotice(message);
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
                var imp = window.KBFShell.user && window.KBFShell.user.impersonation;
                toastNext(imp && imp.targetName
                    ? 'You stopped viewing as ' + imp.targetName + ' and are back in your own account.'
                    : 'You are back in your own account.');
                go(url);
                return;
            }
            // Session already returned to the Super Admin (e.g. after 30 minutes)
            toastNext('You are back in your own account.', 'info');
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
    // Focus in, Tab kept inside, Escape closes, focus back to the opener
    // (js/kbf-dialog.js). Falls back to Escape and focus only.
    function openDialog(wrap, close, first, opener) {
        if (window.KBFDialog) {
            window.KBFDialog.open(wrap, { onClose: close, initialFocus: first, opener: opener || null });
            return;
        }
        var back = opener || document.activeElement;
        wrap.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        var observer = window.MutationObserver ? new MutationObserver(function () {
            if (!wrap.isConnected) {
                observer.disconnect();
                if (back && back.isConnected) back.focus();
            }
        }) : null;
        if (observer) observer.observe(document.body, { childList: true });
        if (first) first.focus();
    }

    function openImpersonateDialog(person, opener) {
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
                    '<button type="button" class="kbf-icon-btn -mt-2 -mr-2 text-gray-400 hover:text-white" data-kbf-close aria-label="Close">' + icon('close', 'w-5 h-5') + '</button>' +
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
        openDialog(wrap, close, password, opener);

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
                    toastNext('You are now viewing the site as ' + (person.name || 'this person') + '.');
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
    }

    // ---------- sign out ----------
    // Tell the other open staff tabs (js/session-manager.js) to follow
    function tellOtherTabs() {
        try {
            if (typeof sessionManager !== 'undefined' && sessionManager && typeof sessionManager.post === 'function') {
                sessionManager.loggingOut = true;
                sessionManager.post({ type: 'logout', reason: 'signout' });
            }
        } catch (e) { /* ignore */ }
    }

    function signOut() {
        tellOtherTabs();
        fetch('/api/auth/logout.php', {
            method: 'GET',
            credentials: 'include',
            headers: { 'X-CSRF-Token': csrfToken() }
        }).catch(function () { /* ignore */ }).then(function () {
            try { sessionStorage.removeItem('csrf_token'); } catch (e) { /* ignore */ }
            go('/login');
        });
    }

    // ---------- sign out everywhere (own account, every device) ----------
    function signOutEverywhere(opener) {
        var question = {
            title: 'Sign out everywhere?',
            message: 'Sign out on every device, including this one? You will need to sign in again.',
            confirmText: 'Sign out everywhere',
            cancelText: 'Cancel',
            danger: true,
            // Focus goes back to the account button, not the hidden menu item
            opener: opener || null
        };
        var ask = window.KBFDialog
            ? window.KBFDialog.confirm(question)
            : Promise.resolve(window.confirm(question.message));
        ask.then(function (yes) { if (yes) doSignOutEverywhere(); });
    }

    // Result of an action: the shared toast (js/kbf-toast.js)
    function toast(text, type) {
        if (window.KBF && typeof window.KBF.toast === 'function') return window.KBF.toast(text, type);
        if (window.KBFDialog) return window.KBFDialog.toast(text, type);
    }

    // Show text on the next page, after go()
    function toastNext(text, type) {
        if (window.KBF && window.KBF.toast && typeof window.KBF.toast.next === 'function') window.KBF.toast.next(text, type);
    }

    function failNotice(text) {
        if (window.KBF && typeof window.KBF.toast === 'function') window.KBF.toast(text, 'error');
        else if (window.KBFDialog) window.KBFDialog.notice({ title: 'Something went wrong', message: text });
        else window.alert(text);
    }

    function doSignOutEverywhere() {
        fetch('/api/auth/sign-out-everywhere.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: '{}'
        }).then(function (res) {
            return res.json().catch(function () { return { success: false }; });
        }).then(function (data) {
            if (data && data.success) {
                tellOtherTabs();
                try { sessionStorage.removeItem('csrf_token'); } catch (e) { /* ignore */ }
                toastNext('You have been signed out on every device.');
                go('/login');
                return;
            }
            failNotice((data && data.message) ? String(data.message) : 'Could not sign out everywhere. Please try again.');
        }).catch(function () {
            failNotice('Could not sign out everywhere. Please try again.');
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

    function openPasswordDialog(opener) {
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
                    '<button type="button" class="kbf-icon-btn -mt-2 -mr-2 text-gray-400 hover:text-white" data-kbf-close aria-label="Close">' + icon('close', 'w-5 h-5') + '</button>' +
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
        openDialog(wrap, close, wrap.querySelector('#kbf-pw-current'), opener);

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
                    close();
                    toast('Your password has been changed.');
                } else {
                    show((data && data.message) ? String(data.message) : 'Your password could not be changed.', false);
                }
            }).catch(function () {
                show('Your password could not be changed. Please try again.', false);
            }).then(function () {
                submit.disabled = false;
            });
        });
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
                    renderFooter();
                    if (opts.controlSection) renderControlNav(user, opts.controlSection);
                    var saved = 'emerald';
                    try { saved = localStorage.getItem('badminton-theme') || 'emerald'; } catch (e) { /* ignore */ }
                    applyTheme(saved);

                    document.documentElement.style.visibility = 'visible';
                    // Toasts sit below the header now that it is drawn
                    if (window.KBF && window.KBF.toast && typeof window.KBF.toast.place === 'function') window.KBF.toast.place();
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
