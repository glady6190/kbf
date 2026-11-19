/* ============================================
 * KBF results form (enter, edit and view results, one category at a time)
 * Shared by admin/submit-tournament-results.html, clubadmin/submit-results.html
 * and admin/edit-results.html.
 *
 *   KBFResultsForm.mount(hostElement, {
 *       tournamentId: 12,
 *       backHref: '/admin/tournaments', backLabel: 'Tournaments',
 *       edit: false,          // true: start on a category that has results
 *       category: ''          // optional category name to open first
 *   });
 *
 * Data: GET /api/matches.php?action=form&tournamentId=
 * Players: searched as you type, GET ?action=formPlayers&tournamentId=&q=
 * (at most 8); drafted players looked up with &ids=
 * Saving: POST (new category), PUT (change a category), DELETE (results
 * waiting for approval) on /api/matches.php.
 *
 * Every value from the server is written with textContent or .value,
 * never as HTML. Drafts are kept in localStorage (inside try/catch).
 * ============================================ */
(function () {
    'use strict';

    var API = '/api/matches.php';
    var DRAFT_PREFIX = 'kbf-results-draft:';

    var ROUNDS = {
        quarter: { title: 'Quarter-finalists', short: 'quarter-final', apiRound: 'QuarterFinal', teams: 4 },
        semi: { title: 'Semi-finalists', short: 'semi-final', apiRound: 'SemiFinal', teams: 2 },
        final: { title: 'Final', short: 'final', apiRound: 'Final', teams: 2 }
    };
    var ROUND_ORDER = ['quarter', 'semi', 'final'];   // columns at lg, left to right
    var STEPS = ['final', 'semi', 'quarter', 'check']; // steps on smaller screens
    var STEP_TITLES = { final: 'Final', semi: 'Semi-finalists', quarter: 'Quarter-finalists', check: 'Check' };

    // Constant icon markup only (never data)
    var ICONS = {
        back: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>',
        pin: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>',
        user: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
        plus: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"/>',
        close: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        check: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>',
        trophy: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 21h8m-4-4v4m-5-17h10v5a5 5 0 01-10 0V4zM7 6H4v2a3 3 0 003 3m10-5h3v2a3 3 0 01-3 3"/>',
        search: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>',
        warn: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',
        info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        list: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
        trash: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
        next: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>'
    };

    function injectStyles() {
        if (document.getElementById('rf-styles')) return;
        var style = document.createElement('style');
        style.id = 'rf-styles';
        style.textContent =
            '.rf-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;font-weight:900;text-transform:uppercase;font-size:.75rem;padding:.7rem 1.1rem;border:2px solid #374151;background:#1e293b;color:#e5e7eb;white-space:nowrap;}' +
            '.rf-btn:hover:not(:disabled){border-color:#6b7280;color:#fff;}' +
            '.rf-btn:disabled{opacity:.4;cursor:not-allowed;}' +
            '.rf-btn-solid{background:var(--theme-primary);border-color:var(--theme-primary);color:#0F172A;box-shadow:5px 5px 0 #020617;transition:transform .15s,box-shadow .15s;}' +
            '.rf-btn-solid:hover:not(:disabled){color:#0F172A;border-color:var(--theme-primary);transform:translate(-2px,-2px);box-shadow:7px 7px 0 #020617;}' +
            '.rf-btn-outline{background:transparent;border-color:var(--theme-primary);color:var(--theme-primary);}' +
            '.rf-btn-outline:hover:not(:disabled){background:var(--theme-primary);color:#0F172A;border-color:var(--theme-primary);}' +
            '.rf-btn-sm{padding:.35rem .6rem;font-size:.65rem;}' +
            // Touch targets at least 44px
            '.rf-btn,.rf-chip-btn{min-height:44px;}' +
            '.rf-btn:focus-visible,.rf-chip-btn:focus-visible,.rf-x:focus-visible{outline:2px solid #fff;outline-offset:2px;}' +
            '.rf-date{width:4rem;flex-shrink:0;border:2px solid #374151;background:#0f172a;text-align:center;padding:.25rem 0 .3rem;line-height:1.05;}' +
            '.rf-date-month{display:block;color:var(--theme-primary);font-size:.7rem;font-weight:900;text-transform:uppercase;}' +
            '.rf-date-day{display:block;color:#fff;font-size:1.6rem;font-weight:900;}' +
            '.rf-chip-btn{display:inline-flex;flex-direction:column;align-items:flex-start;gap:.1rem;padding:.5rem .8rem;border:2px solid #374151;background:#0f172a;color:#e5e7eb;text-align:left;min-width:7rem;}' +
            '.rf-chip-btn:hover{border-color:#6b7280;}' +
            '.rf-chip-btn .rf-chip-name{font-weight:900;font-size:.8rem;text-transform:uppercase;}' +
            '.rf-chip-btn .rf-chip-state{font-weight:800;font-size:.62rem;text-transform:uppercase;color:#9ca3af;}' +
            '.rf-chip-btn.is-done .rf-chip-state{color:var(--theme-primary);}' +
            '.rf-chip-btn.is-active{background:var(--theme-primary);border-color:var(--theme-primary);color:#0F172A;box-shadow:4px 4px 0 #020617;transform:translate(-2px,-2px);}' +
            '.rf-chip-btn.is-active .rf-chip-state{color:#0F172A;}' +
            '.rf-field{width:100%;background:#1e293b;border:2px solid #374151;color:#fff;padding:.55rem .7rem;font-weight:700;font-size:.8125rem;}' +
            '@media (max-width:639.98px){.rf-field{font-size:16px;}}' +
            '.rf-field:focus{outline:none;border-color:var(--theme-primary);}' +
            '.rf-round{background:#0f172a;border:2px solid #374151;padding:1rem;}' +
            '.rf-team{background:#111827;border:2px solid #374151;padding:.75rem;}' +
            '.rf-team.is-winner{border-color:var(--theme-primary);box-shadow:inset 0 0 0 1px var(--theme-primary);}' +
            '.rf-tag{display:inline-block;padding:.1rem .4rem;border:2px solid #4b5563;background:#1e293b;color:#e5e7eb;font-size:.62rem;font-weight:900;text-transform:uppercase;white-space:nowrap;}' +
            '.rf-tag.is-winner{background:var(--theme-primary);border-color:var(--theme-primary);color:#0F172A;}' +
            '.rf-win-btn{width:100%;margin-top:.6rem;}' +
            '.rf-win-btn[aria-pressed="true"]{background:var(--theme-primary);border-color:var(--theme-primary);color:#0F172A;}' +
            '.rf-guest-btn{display:inline-flex;align-items:center;min-height:44px;font-size:.62rem;font-weight:900;text-transform:uppercase;color:#9ca3af;border:2px solid #374151;padding:.1rem .6rem;}' +
            '.rf-guest-btn:hover:not(:disabled){color:#fff;border-color:#6b7280;}' +
            '.rf-guest-btn:disabled{opacity:.35;cursor:not-allowed;}' +
            '.rf-guest-btn:focus-visible{outline:2px solid #fff;outline-offset:2px;}' +
            '.rf-slot{position:relative;}' +
            '.rf-list{position:absolute;left:0;right:0;top:100%;margin-top:.2rem;z-index:30;background:#0f172a;border:2px solid var(--theme-primary);box-shadow:4px 4px 0 #020617;max-height:22rem;overflow-y:auto;}' +
            '.rf-option{display:flex;align-items:center;gap:.6rem;padding:.5rem .6rem;cursor:pointer;border-bottom:1px solid #1e293b;}' +
            '.rf-option:last-child{border-bottom:none;}' +
            '.rf-option.is-active,.rf-option:hover{background:#1e293b;}' +
            '.rf-option.is-guest{color:#d1d5db;font-weight:800;font-size:.75rem;}' +
            '.rf-avatar{width:2.1rem;height:2.1rem;border-radius:9999px;border:2px solid var(--theme-primary);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:#1e293b;color:#e5e7eb;font-weight:900;font-size:.68rem;}' +
            '.rf-avatar img{width:100%;height:100%;object-fit:cover;}' +
            '.rf-avatar.is-guest{border-color:#4b5563;color:#9ca3af;}' +
            '.rf-grade{width:1.9rem;height:1.9rem;display:inline-flex;align-items:center;justify-content:center;border:2px solid var(--theme-primary);color:var(--theme-primary);font-weight:900;font-size:.72rem;flex-shrink:0;}' +
            '.rf-grade.is-none{border-color:#374151;color:#6b7280;}' +
            '.rf-picked{display:flex;align-items:center;gap:.6rem;background:#1e293b;border:2px solid #374151;padding:.4rem .5rem;}' +
            '.rf-x{display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;padding:.2rem;color:#9ca3af;flex-shrink:0;}' +
            '.rf-x:hover{color:#fff;}' +
            '.rf-msg{padding:.75rem;border:2px solid;font-weight:700;font-size:.78rem;}' +
            '.rf-msg-ok{border-color:var(--theme-primary);color:#e5e7eb;background:#1e293b;}' +
            '.rf-msg-error{border-color:#ef4444;color:#fca5a5;background:rgba(239,68,68,.12);}' +
            '.rf-msg-warn{border-color:#f59e0b;color:#fde68a;background:rgba(245,158,11,.1);}' +
            '.rf-warn{color:#fbbf24;}' +
            '.rf-modal-wrap{position:fixed;inset:0;background:rgba(2,6,23,.82);z-index:60;display:flex;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto;}' +
            '@media (min-width:640px){.rf-modal-wrap{align-items:center;}}' +
            '.rf-modal{background:#0f172a;border:4px solid #374151;width:100%;max-width:36rem;margin:2rem 0;}' +
            '@media (max-width:1023px){.rf-step-hidden{display:none !important;}}';
        document.head.appendChild(style);
    }

    // ---------- small DOM helpers ----------
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function icon(name, className) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('class', className || 'w-4 h-4');
        svg.innerHTML = ICONS[name] || '';
        return svg;
    }

    function btn(className, text, iconName) {
        var b = el('button', className);
        b.type = 'button';
        if (iconName) b.appendChild(icon(iconName, 'w-4 h-4 flex-shrink-0'));
        if (text) b.appendChild(document.createTextNode(text));
        return b;
    }

    function safePhoto(path) {
        return (typeof path === 'string' && /^\/uploads\/(profiles|registrations)\/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$/i.test(path)) ? path : null;
    }

    function initials(first, last) {
        var a = String(first || '').trim().charAt(0);
        var b = String(last || '').trim().charAt(0);
        return ((a + b) || '?').toUpperCase();
    }

    function avatar(player) {
        var box = el('span', 'rf-avatar' + (player.kind === 'guest' ? ' is-guest' : ''));
        box.setAttribute('aria-hidden', 'true');
        var src = player.kind === 'player' ? safePhoto(player.photo) : null;
        if (src) {
            var img = el('img');
            img.alt = '';
            img.loading = 'lazy';
            img.decoding = 'async';
            img.width = 30;
            img.height = 30;
            if (window.KBF && window.KBF.setImageVariant) window.KBF.setImageVariant(img, src, 'thumb');
            else img.src = src;
            box.appendChild(img);
        } else if (player.kind === 'guest') {
            var parts = String(player.name || '').trim().split(/\s+/);
            box.textContent = initials(parts[0], parts.length > 1 ? parts[parts.length - 1] : '');
        } else {
            box.textContent = initials(player.first, player.last);
        }
        return box;
    }

    function gradeBox(grade) {
        return el('span', 'rf-grade' + (grade ? '' : ' is-none'), grade || '-');
    }

    function fullName(p) {
        if (!p) return '';
        if (p.kind === 'guest') return p.name;
        return (String(p.first || '') + ' ' + String(p.last || '')).trim();
    }

    function categoryKey(name) {
        return String(name || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function cleanCategory(name) {
        return String(name || '').replace(/[\u0000-\u001F\u007F]+/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function parseDate(value) {
        if (!value) return null;
        var d = new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? value + 'T12:00:00' : value);
        return isNaN(d.getTime()) ? null : d;
    }

    function plural(n, word) {
        return n + ' ' + word + (n === 1 ? '' : 's');
    }

    // "2, 3 and 4"
    function listAnd(items) {
        if (items.length <= 1) return items.join('');
        return items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1];
    }

    function getCSRFToken() {
        try { return sessionStorage.getItem('csrf_token'); } catch (e) { return null; }
    }

    function request(url, options) {
        options = options || {};
        options.headers = options.headers || {};
        var token = getCSRFToken();
        if (token) options.headers['X-CSRF-Token'] = token;
        options.credentials = 'include';
        return fetch(url, options).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                data = data || {};
                data._status = res.status;
                return data;
            });
        });
    }

    var uid = 0;
    function nextId(prefix) { uid += 1; return prefix + uid; }

    // ============================================
    // MOUNT
    // ============================================
    function mount(host, options) {
        injectStyles();
        var opts = Object.assign({ tournamentId: 0, backHref: '/', backLabel: 'Back', edit: false, category: '' }, options || {});
        var tid = parseInt(opts.tournamentId, 10) || 0;

        var data = null;          // GET ?action=form
        var players = [];         // players already in this tournament's results
        var knownPlayers = {};    // id -> player, from the form and from searches
        var categories = [];      // server categories plus names typed on this page

        function rememberPlayer(p) {
            if (p && p.kind === 'player' && p.id) knownPlayers[p.id] = p;
        }

        // Up to 8 players matching the text (KBF ID first, then names
        // starting with it, then other matches), leaving out those taken
        function searchPlayers(raw, taken) {
            var exclude = Object.keys(taken).slice(0, 16).join(',');
            return request(API + '?action=formPlayers&tournamentId=' + encodeURIComponent(String(tid)) +
                    '&q=' + encodeURIComponent(raw) + (exclude ? '&exclude=' + encodeURIComponent(exclude) : ''))
                .then(function (res) {
                    if (!res || !res.success) return [];
                    return (res.players || []).map(function (p) { p.kind = 'player'; rememberPlayer(p); return p; });
                })
                .catch(function () { return []; });
        }

        // Players by id that can still be chosen (for a draft), looked up on the server
        function fetchPlayersById(ids) {
            var missing = ids.filter(function (id) { return !knownPlayers[id]; }).slice(0, 16);
            if (!missing.length) return Promise.resolve();
            return request(API + '?action=formPlayers&tournamentId=' + encodeURIComponent(String(tid)) +
                    '&ids=' + encodeURIComponent(missing.join(',')))
                .then(function (res) {
                    if (res && res.success) {
                        (res.players || []).forEach(function (p) { p.kind = 'player'; rememberPlayer(p); });
                    }
                })
                .catch(function () { /* drafted players that cannot be found are left out */ });
        }
        var current = null;       // the selected category
        var mode = 'enter';       // 'enter' | 'edit' | 'readonly'
        var teams = null;         // { quarter: [[a,b] x4], semi: [[a,b] x2], final: [[a,b] x2] }
        var winner = 0;
        var step = 0;
        var dirty = false;
        var draftPending = false; // waiting for "Continue" or "Start again"
        var draftSaved = false;
        var slotUi = {};          // per slot: { guest: bool, text: '' }
        var careerNotes = {};     // userId => { kind, date, grade } (career points only; decided by the server)
        var notesKey = '';
        var notesTimer = null;
        var refs = {};
        var busy = false;

        host.textContent = '';
        var root = el('div', 'space-y-6');
        host.appendChild(root);

        // ---------- header ----------
        var header = el('div');
        var back = el('a', 'inline-flex items-center gap-2 mb-4 text-xs font-black uppercase text-gray-400 hover:text-white');
        back.href = opts.backHref;
        back.appendChild(icon('back', 'w-4 h-4'));
        back.appendChild(document.createTextNode(opts.backLabel));
        header.appendChild(back);
        var headRow = el('div', 'flex items-start gap-4');
        var dateBox = el('div');
        headRow.appendChild(dateBox);
        var headText = el('div', 'min-w-0');
        var title = el('h1', 'text-2xl sm:text-3xl lg:text-4xl font-black uppercase theme-primary-text break-words', 'Results');
        headText.appendChild(title);
        var venueLine = el('p', 'flex items-center gap-1 text-sm text-gray-300 font-bold mt-1');
        var organiserLine = el('p', 'flex items-center gap-1 text-sm text-gray-400 font-bold mt-0.5');
        headText.appendChild(venueLine);
        headText.appendChild(organiserLine);
        headRow.appendChild(headText);
        header.appendChild(headRow);
        header.appendChild(el('div', 'h-2 w-24 sm:w-32 theme-primary-bg mt-4 mb-3'));
        header.appendChild(el('p', 'text-gray-400 font-bold text-xs sm:text-sm', 'Pick the category, add the players for each round, mark the winners, then check and publish.'));
        root.appendChild(header);

        var pageMsg = el('div', 'rf-msg hidden');
        pageMsg.setAttribute('role', 'status');
        pageMsg.setAttribute('aria-live', 'polite');
        root.appendChild(pageMsg);

        var loading = el('p', 'py-12 text-center text-gray-400 font-bold uppercase text-sm', 'Loading...');
        root.appendChild(loading);

        // "Could not be loaded" with Try again (KBF.showLoadError)
        var loadError = el('div', 'hidden');
        root.appendChild(loadError);

        var body = el('div', 'hidden space-y-6');
        root.appendChild(body);

        function showMsg(text, kind) {
            if (!text) { pageMsg.classList.add('hidden'); return; }
            pageMsg.textContent = text;
            pageMsg.className = 'rf-msg ' + (kind === 'error' ? 'rf-msg-error' : (kind === 'warn' ? 'rf-msg-warn' : 'rf-msg-ok'));
            pageMsg.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        }

        // ---------- categories ----------
        var catSection = el('section', 'bg-slate-900 border-2 sm:border-4 border-gray-700 p-4 sm:p-5');
        catSection.setAttribute('aria-labelledby', 'rfCatHeading');
        var catHeading = el('h2', 'text-xs font-black uppercase theme-primary-text mb-3', 'Category');
        catHeading.id = 'rfCatHeading';
        catSection.appendChild(catHeading);
        var chipRow = el('div', 'flex flex-wrap gap-3 pt-1 pl-1');
        chipRow.setAttribute('role', 'group');
        chipRow.setAttribute('aria-labelledby', 'rfCatHeading');
        catSection.appendChild(chipRow);
        var customRow = el('div', 'hidden mt-3 flex flex-col sm:flex-row gap-2');
        var customInput = el('input', 'rf-field sm:max-w-sm');
        customInput.type = 'text';
        customInput.maxLength = 50;
        customInput.placeholder = 'Division name';
        customInput.setAttribute('aria-label', 'Another division name');
        var customUse = btn('rf-btn', 'Use this name');
        var customCancel = btn('rf-btn', 'Cancel');
        customRow.appendChild(customInput);
        customRow.appendChild(customUse);
        customRow.appendChild(customCancel);
        catSection.appendChild(customRow);
        catSection.appendChild(el('p', 'text-xs text-gray-400 font-semibold mt-3', 'Submit one category at a time. The categories come from the tournament details.'));
        body.appendChild(catSection);

        // ---------- notes for the chosen category ----------
        var modeNote = el('div', 'rf-msg hidden');
        body.appendChild(modeNote);

        var draftOffer = el('div', 'hidden bg-slate-900 border-2 border-gray-700 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3');
        var draftText = el('p', 'text-sm font-bold text-gray-200');
        var draftButtons = el('div', 'flex gap-2');
        var draftContinue = btn('rf-btn rf-btn-outline', 'Continue');
        var draftRestart = btn('rf-btn', 'Start again');
        draftButtons.appendChild(draftContinue);
        draftButtons.appendChild(draftRestart);
        draftOffer.appendChild(draftText);
        draftOffer.appendChild(draftButtons);
        body.appendChild(draftOffer);

        // ---------- steps (smaller screens) ----------
        var stepBar = el('p', 'lg:hidden text-xs font-black uppercase text-gray-400');
        stepBar.setAttribute('aria-live', 'polite');
        body.appendChild(stepBar);

        var bracket = el('div', 'grid grid-cols-1 lg:grid-cols-3 gap-4 items-start');
        body.appendChild(bracket);

        var checkPanel = el('section', 'bg-slate-900 border-2 sm:border-4 border-gray-700 p-4 sm:p-5 space-y-3');
        checkPanel.setAttribute('aria-labelledby', 'rfCheckHeading');
        var checkHeading = el('h2', 'text-sm font-black uppercase theme-primary-text', 'Before you publish');
        checkHeading.id = 'rfCheckHeading';
        var checkStats = el('p', 'text-sm font-bold text-gray-200');
        var checkProblems = el('ul', 'space-y-1');
        var checkDraft = el('p', 'hidden text-xs font-semibold text-gray-400', 'Draft saved on this device');
        checkPanel.appendChild(checkHeading);
        checkPanel.appendChild(checkStats);
        checkPanel.appendChild(checkProblems);
        checkPanel.appendChild(checkDraft);
        body.appendChild(checkPanel);

        var stepNav = el('div', 'lg:hidden flex justify-between gap-2');
        var stepBack = btn('rf-btn', 'Back', 'back');
        var stepNext = btn('rf-btn rf-btn-outline', 'Next');
        stepNext.appendChild(icon('next', 'w-4 h-4'));
        stepNav.appendChild(stepBack);
        stepNav.appendChild(stepNext);
        body.appendChild(stepNav);

        var actions = el('div', 'flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3');
        var deleteBtn = btn('rf-btn hidden sm:mr-auto', 'Delete these results', 'trash');
        var checkBtn = btn('rf-btn', 'Check results', 'list');
        var mainBtn = btn('rf-btn rf-btn-solid', 'Publish results');
        actions.appendChild(deleteBtn);
        actions.appendChild(checkBtn);
        actions.appendChild(mainBtn);
        body.appendChild(actions);
        var mainNote = el('p', 'hidden text-xs font-semibold text-gray-400 text-right', 'KBF approves your results before points are given.');
        body.appendChild(mainNote);

        // ============================================
        // MODALS (confirm and summary)
        // Focus, Tab trap, Escape, inert background and return focus come
        // from the shared helper (js/kbf-dialog.js).
        // ============================================
        var openModals = [];

        function openModal(wrap, onClose, initialFocus) {
            document.body.appendChild(wrap);
            openModals.push(wrap);
            document.body.style.overflow = 'hidden';
            if (window.KBFDialog) {
                window.KBFDialog.open(wrap, { onClose: onClose, initialFocus: initialFocus || null });
            } else if (initialFocus) {
                initialFocus.focus();
            }
        }

        function closeModal(wrap) {
            var i = openModals.indexOf(wrap);
            if (i === -1) return;
            openModals.splice(i, 1);
            if (window.KBFDialog) window.KBFDialog.close(wrap);
            if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
            if (!openModals.length) document.body.style.overflow = '';
        }

        function modalShell(titleText) {
            var wrap = el('div', 'rf-modal-wrap');
            wrap.setAttribute('role', 'dialog');
            wrap.setAttribute('aria-modal', 'true');
            var box = el('div', 'rf-modal p-5 sm:p-6');
            var h = el('h2', 'text-lg font-black uppercase theme-primary-text mb-3', titleText);
            h.id = nextId('rfModalTitle');
            wrap.setAttribute('aria-labelledby', h.id);
            box.appendChild(h);
            wrap.appendChild(box);
            return { wrap: wrap, box: box };
        }

        // Resolves true or false
        function askConfirm(titleText, text, yesText, noText) {
            return new Promise(function (resolve) {
                var m = modalShell(titleText);
                var p = el('p', 'text-sm text-gray-300 font-semibold mb-5', text);
                p.id = nextId('rfModalText');
                m.wrap.setAttribute('aria-describedby', p.id);
                m.box.appendChild(p);
                var row = el('div', 'flex flex-col-reverse sm:flex-row sm:justify-end gap-2');
                var no = btn('rf-btn', noText || 'Go back');
                var yes = btn('rf-btn rf-btn-solid', yesText);
                row.appendChild(no);
                row.appendChild(yes);
                m.box.appendChild(row);
                var done = false;
                function finish(answer) {
                    if (done) return;
                    done = true;
                    closeModal(m.wrap);
                    resolve(answer);
                }
                no.addEventListener('click', function () { finish(false); });
                yes.addEventListener('click', function () { finish(true); });
                openModal(m.wrap, function () { finish(false); }, no);
            });
        }

        // ============================================
        // LOAD
        // ============================================
        function loadFailed(text, retry) {
            loading.classList.add('hidden');
            body.classList.add('hidden');
            loadError.classList.remove('hidden');
            if (window.KBF && typeof window.KBF.showLoadError === 'function') {
                window.KBF.showLoadError(loadError, { message: text, retry: retry || null });
            } else {
                loadError.textContent = text;
            }
        }

        function load(selectKey, opening) {
            function retry() {
                loadError.classList.add('hidden');
                loading.classList.remove('hidden');
                load(selectKey, opening);
            }
            return request(API + '?action=form&tournamentId=' + encodeURIComponent(String(tid))).then(function (res) {
                if (!res.success) {
                    // Server or connection trouble can be retried; "not
                    // found" or "not allowed" cannot
                    var canRetry = !res._status || res._status >= 500;
                    loadFailed(res.message || 'This tournament could not be loaded.', canRetry ? retry : null);
                    return false;
                }
                loadError.classList.add('hidden');
                data = res;
                // Players already in the results; others are searched on
                // the server as the organiser types (formPlayers)
                players = (res.players || []).map(function (p) { p.kind = 'player'; return p; });
                players.forEach(rememberPlayer);
                renderHeader();
                if (!res.canEnter) {
                    loading.classList.add('hidden');
                    body.classList.add('hidden');
                    showMsg(res.blockedMessage || 'Results cannot be entered for this tournament.', 'warn');
                    return false;
                }
                // Keep names typed on this page that still have no results
                var typed = categories.filter(function (c) { return c.custom; });
                categories = (res.categories || []).slice();
                typed.forEach(function (c) {
                    if (!categories.some(function (s) { return s.key === c.key; })) categories.push(c);
                });
                loading.classList.add('hidden');
                body.classList.remove('hidden');
                if (opening) {
                    selectKey = pickStart();
                }
                selectCategory(selectKey, true);
                return true;
            }).catch(function () {
                loadFailed('This tournament could not be loaded. Please check your connection and try again.', retry);
            });
        }

        function pickStart() {
            var wanted = categoryKey(opts.category);
            if (wanted && categories.some(function (c) { return c.key === wanted; })) return wanted;
            if (opts.edit) {
                var done = categories.filter(function (c) { return c.state !== 'none'; })[0];
                if (done) return done.key;
            }
            var todo = categories.filter(function (c) { return c.state === 'none'; })[0];
            if (todo) return todo.key;
            return categories.length ? categories[0].key : null;
        }

        function renderHeader() {
            var t = data.tournament;
            document.title = 'Results: ' + t.name;
            title.textContent = 'Results: ' + t.name;
            dateBox.textContent = '';
            var tile = el('div', 'rf-date');
            var d = parseDate(t.date);
            tile.appendChild(el('span', 'rf-date-month', d ? KBF.MONTHS_SHORT[d.getMonth()] : '-'));
            tile.appendChild(el('span', 'rf-date-day', d ? String(d.getDate()) : '-'));
            if (d) tile.title = KBF.formatDate(d, { weekday: 'long', month: 'long' });
            dateBox.appendChild(tile);
            venueLine.textContent = '';
            venueLine.appendChild(icon('pin', 'w-4 h-4 flex-shrink-0 text-gray-500'));
            venueLine.appendChild(document.createTextNode([t.venue, t.town].filter(Boolean).join(', ') || '-'));
            organiserLine.textContent = '';
            if (t.organiser) {
                organiserLine.appendChild(icon('user', 'w-4 h-4 flex-shrink-0 text-gray-500'));
                organiserLine.appendChild(document.createTextNode('Organiser: ' + t.organiser));
            }
        }

        // ============================================
        // CATEGORIES
        // ============================================
        function renderChips() {
            chipRow.textContent = '';
            categories.forEach(function (c) {
                var selected = current && current.key === c.key;
                var submitted = c.state !== 'none';
                var b = el('button', 'rf-chip-btn' + (selected ? ' is-active' : '') + (submitted ? ' is-done' : ''));
                b.type = 'button';
                b.setAttribute('aria-pressed', selected ? 'true' : 'false');
                b.appendChild(el('span', 'rf-chip-name', c.name || 'No category name'));
                var stateText = submitted ? 'Submitted' : (selected ? 'Entering now' : 'Not yet');
                var st = el('span', 'rf-chip-state');
                if (submitted) st.appendChild(icon('check', 'w-3 h-3 inline-block mr-1 align-[-1px]'));
                st.appendChild(document.createTextNode(stateText));
                b.appendChild(st);
                if (c.state === 'waiting') b.title = 'Waiting for approval';
                if (c.state === 'approved') b.title = 'Approved';
                b.addEventListener('click', function () { chooseCategory(c.key); });
                chipRow.appendChild(b);
            });
            var add = el('button', 'rf-chip-btn');
            add.type = 'button';
            var addName = el('span', 'rf-chip-name inline-flex items-center gap-1');
            addName.appendChild(icon('plus', 'w-3 h-3'));
            addName.appendChild(document.createTextNode('Another division name'));
            add.appendChild(addName);
            add.addEventListener('click', function () {
                customRow.classList.remove('hidden');
                customInput.value = '';
                customInput.focus();
            });
            chipRow.appendChild(add);
        }

        function useCustomName() {
            var name = cleanCategory(customInput.value);
            if (!name) { customInput.focus(); return; }
            if (name.length > 50) name = name.slice(0, 50);
            var key = categoryKey(name);
            if (!categories.some(function (c) { return c.key === key; })) {
                categories.push({ key: key, name: name, state: 'none', canEdit: false, canDelete: false, matches: [], custom: true });
            }
            customRow.classList.add('hidden');
            chooseCategory(key);
        }
        customUse.addEventListener('click', useCustomName);
        customInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); useCustomName(); }
            if (e.key === 'Escape') { e.preventDefault(); customRow.classList.add('hidden'); }
        });
        customCancel.addEventListener('click', function () { customRow.classList.add('hidden'); });

        function chooseCategory(key) {
            if (current && current.key === key) return;
            if (dirty && mode === 'edit') {
                askConfirm('Leave without saving?', 'Your changes to ' + (current.name || 'these results') + ' have not been saved.', 'Leave without saving', 'Stay').then(function (ok) {
                    if (ok) selectCategory(key, false);
                });
                return;
            }
            selectCategory(key, false);
        }

        function emptyTeams() {
            var t = {};
            ROUND_ORDER.forEach(function (r) {
                t[r] = [];
                for (var i = 0; i < ROUNDS[r].teams; i++) t[r].push([null, null]);
            });
            return t;
        }

        // Turn saved matches into teams
        function teamsFromMatches(matches) {
            var t = emptyTeams();
            var w = 0;
            var lists = { quarter: [], semi: [] };
            (matches || []).forEach(function (m) {
                if (m.round === 'Final' && !t.finalSet) {
                    t.final[0] = [m.teams[0][0], m.teams[0][1]];
                    t.final[1] = [m.teams[1][0], m.teams[1][1]];
                    w = (m.winner === 1 || m.winner === 2) ? m.winner : 0;
                    t.finalSet = true;
                } else if (m.round === 'SemiFinal') {
                    lists.semi.push(m.teams[0], m.teams[1]);
                } else if (m.round === 'QuarterFinal') {
                    lists.quarter.push(m.teams[0], m.teams[1]);
                }
            });
            delete t.finalSet;
            ['quarter', 'semi'].forEach(function (r) {
                lists[r].slice(0, ROUNDS[r].teams).forEach(function (team, i) { t[r][i] = [team[0], team[1]]; });
            });
            return { teams: t, winner: w };
        }

        function selectCategory(key, keepMessage) {
            if (!keepMessage) showMsg('');
            current = categories.filter(function (c) { return c.key === key; })[0] || null;
            dirty = false;
            draftPending = false;
            draftSaved = false;
            slotUi = {};
            step = 0;
            if (!current) {
                mode = 'enter';
                teams = emptyTeams();
                winner = 0;
            } else if (current.state === 'none') {
                mode = 'enter';
                teams = emptyTeams();
                winner = 0;
            } else {
                mode = current.canEdit ? 'edit' : 'readonly';
                var loaded = teamsFromMatches(current.matches);
                teams = loaded.teams;
                winner = loaded.winner;
            }
            renderChips();
            renderModeNote();
            offerDraft();
            renderBracket();
            renderActions();
            refresh();
            if (!current) {
                customRow.classList.remove('hidden');
            }
        }

        function renderModeNote() {
            modeNote.classList.add('hidden');
            if (!current) {
                modeNote.className = 'rf-msg rf-msg-ok';
                modeNote.textContent = 'This tournament has no categories listed. Add a division name to start.';
                return;
            }
            if (mode === 'readonly') {
                modeNote.className = 'rf-msg rf-msg-ok';
                modeNote.textContent = current.state === 'approved'
                    ? 'These results are approved. Ask KBF if anything needs changing.'
                    : 'You can see these results but not change them.';
            } else if (mode === 'edit' && current.state === 'approved') {
                modeNote.className = 'rf-msg rf-msg-warn';
                modeNote.textContent = 'These results are approved. Saving reverses the points they gave and works them out again.';
            } else if (mode === 'edit') {
                modeNote.className = 'rf-msg rf-msg-ok';
                modeNote.textContent = 'These results are waiting for approval. You can still change or delete them.';
            }
        }

        // ============================================
        // DRAFTS (this device only)
        // ============================================
        function draftKey() {
            return current ? DRAFT_PREFIX + tid + ':' + current.key : null;
        }

        function readDraft() {
            var key = draftKey();
            if (!key) return null;
            try {
                var raw = window.localStorage.getItem(key);
                var d = raw ? JSON.parse(raw) : null;
                return d && d.teams ? d : null;
            } catch (e) { return null; }
        }

        function removeDraft() {
            var key = draftKey();
            if (!key) return;
            try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
            draftSaved = false;
        }

        function hasAnything() {
            if (winner) return true;
            return ROUND_ORDER.some(function (r) {
                return teams[r].some(function (team) { return team[0] || team[1]; });
            });
        }

        function saveDraft() {
            if (mode !== 'enter' || !current || draftPending) return;
            var key = draftKey();
            try {
                if (!hasAnything()) {
                    window.localStorage.removeItem(key);
                    draftSaved = false;
                } else {
                    window.localStorage.setItem(key, JSON.stringify({ savedAt: new Date().toISOString(), teams: teams, winner: winner }));
                    draftSaved = true;
                }
            } catch (e) {
                draftSaved = false;
            }
        }

        function offerDraft() {
            draftOffer.classList.add('hidden');
            if (mode !== 'enter' || !current) return;
            var d = readDraft();
            if (!d) return;
            var when = parseDate(d.savedAt);
            draftText.textContent = 'Continue your saved draft from ' + (when
                ? KBF.formatDate(when, { year: false, time: true })
                : 'earlier') + '?';
            draftPending = true;
            draftOffer.classList.remove('hidden');
        }

        // A drafted slot: registered players are looked up again
        function restoreSlot(value) {
            if (!value || typeof value !== 'object') return null;
            if (value.kind === 'guest') {
                var name = String(value.name || '').trim().slice(0, 100);
                return name ? { kind: 'guest', name: name } : null;
            }
            var id = parseInt(value.id, 10);
            return knownPlayers[id] || null;
        }

        draftContinue.addEventListener('click', function () {
            var d = readDraft();
            draftPending = false;
            draftOffer.classList.add('hidden');
            if (!d) { mainBtn.focus(); return; }
            // The drafted players are looked up again on the server first
            var ids = [];
            ROUND_ORDER.forEach(function (r) {
                (d.teams[r] || []).forEach(function (team) {
                    (team || []).forEach(function (v) {
                        var id = v && typeof v === 'object' && v.kind !== 'guest' ? parseInt(v.id, 10) : 0;
                        if (id > 0 && ids.indexOf(id) === -1) ids.push(id);
                    });
                });
            });
            var draftCategory = current;
            fetchPlayersById(ids).then(function () {
                if (current !== draftCategory) return;
                var t = emptyTeams();
                ROUND_ORDER.forEach(function (r) {
                    for (var i = 0; i < ROUNDS[r].teams; i++) {
                        var team = d.teams[r] && d.teams[r][i];
                        if (team) t[r][i] = [restoreSlot(team[0]), restoreSlot(team[1])];
                    }
                });
                teams = t;
                winner = (d.winner === 1 || d.winner === 2) ? d.winner : 0;
                dedupe();
                renderBracket();
                refresh();
                mainBtn.focus();
            });
        });

        draftRestart.addEventListener('click', function () {
            draftPending = false;
            removeDraft();
            draftOffer.classList.add('hidden');
            refresh();
        });

        // A player kept only once (a draft could hold an old duplicate)
        function dedupe() {
            var seen = {};
            ROUND_ORDER.forEach(function (r) {
                teams[r].forEach(function (team) {
                    for (var s = 0; s < 2; s++) {
                        var v = team[s];
                        if (v && v.kind === 'player') {
                            if (seen[v.id]) team[s] = null; else seen[v.id] = true;
                        }
                    }
                });
            });
        }

        // ============================================
        // BRACKET
        // ============================================
        function pointsOf(round, teamIdx) {
            var p = data.points;
            if (round === 'quarter') return p.quarter;
            if (round === 'semi') return p.semi;
            if (!winner) return null;
            return winner === teamIdx + 1 ? p.winners : p.runnersUp;
        }

        function teamLabel(round, i) {
            return 'Team ' + (i + 1);
        }

        function renderBracket() {
            bracket.textContent = '';
            refs = {};
            var orderSm = { final: 'order-1', semi: 'order-2', quarter: 'order-3' };
            var orderLg = { quarter: 'lg:order-1', semi: 'lg:order-2', final: 'lg:order-3' };
            var p = data.points;
            ROUND_ORDER.forEach(function (r) {
                var section = el('section', 'rf-round ' + orderSm[r] + ' ' + orderLg[r]);
                var h = el('h2', 'text-base font-black uppercase text-white', ROUNDS[r].title);
                h.id = nextId('rfRound');
                section.setAttribute('aria-labelledby', h.id);
                section.appendChild(h);
                var caption = r === 'final'
                    ? 'Winners ' + p.winners + ' pts, runners-up ' + p.runnersUp + ' pts'
                    : (r === 'semi' ? p.semi : p.quarter) + ' pts each player';
                section.appendChild(el('p', 'text-xs font-bold theme-primary-text mb-3', caption));
                var list = el('div', 'space-y-3');
                refs[r] = { section: section, teams: [] };
                for (var i = 0; i < ROUNDS[r].teams; i++) {
                    list.appendChild(teamCard(r, i));
                }
                section.appendChild(list);
                bracket.appendChild(section);
            });
            applyStep();
        }

        function teamCard(round, i) {
            var card = el('div', 'rf-team');
            var top = el('div', 'flex items-center gap-2 mb-2');
            top.appendChild(el('span', 'text-xs font-black uppercase text-gray-200 flex-1', teamLabel(round, i)));
            var guestBtn = el('button', 'rf-guest-btn inline-flex items-center gap-1');
            guestBtn.type = 'button';
            guestBtn.appendChild(icon('plus', 'w-3 h-3'));
            guestBtn.appendChild(document.createTextNode('Guest'));
            guestBtn.setAttribute('aria-label', 'Add a guest to ' + ROUNDS[round].short + ' team ' + (i + 1));
            guestBtn.addEventListener('click', function () { startGuest(round, i); });
            if (mode !== 'readonly') top.appendChild(guestBtn);
            var tag = el('span', 'rf-tag');
            top.appendChild(tag);
            card.appendChild(top);

            var slots = [];
            for (var s = 0; s < 2; s++) {
                var holder = el('div', 'rf-slot' + (s === 1 ? ' mt-2' : ''));
                card.appendChild(holder);
                slots.push(holder);
            }

            var winBtn = null;
            if (round === 'final' && mode !== 'readonly') {
                winBtn = btn('rf-btn rf-btn-sm rf-win-btn', 'Mark as winners', 'trophy');
                winBtn.setAttribute('aria-pressed', 'false');
                winBtn.addEventListener('click', function () {
                    winner = winner === i + 1 ? 0 : i + 1;
                    changed();
                    paintTeam('final', 0);
                    paintTeam('final', 1);
                });
                card.appendChild(winBtn);
            }

            refs[round].teams[i] = { card: card, tag: tag, guestBtn: guestBtn, slots: slots, winBtn: winBtn };
            paintSlot(round, i, 0);
            paintSlot(round, i, 1);
            paintTeam(round, i);
            return card;
        }

        function paintTeam(round, i) {
            var r = refs[round] && refs[round].teams[i];
            if (!r) return;
            var pts = pointsOf(round, i);
            var isWinner = round === 'final' && winner === i + 1;
            r.card.classList.toggle('is-winner', isWinner);
            r.tag.classList.toggle('is-winner', isWinner);
            if (pts === null) {
                r.tag.textContent = data.points.winners + ' or ' + data.points.runnersUp + ' pts';
            } else {
                r.tag.textContent = pts + ' pts';
            }
            var team = teams[round][i];
            r.guestBtn.disabled = !!(team[0] && team[1]);
            if (r.winBtn) {
                r.winBtn.setAttribute('aria-pressed', isWinner ? 'true' : 'false');
                r.winBtn.lastChild.textContent = isWinner ? 'Winners' : 'Mark as winners';
            }
        }

        // ---------- one player slot ----------
        function uiOf(round, i, s) {
            var key = round + '-' + i + '-' + s;
            if (!slotUi[key]) slotUi[key] = { guest: false, text: '' };
            return slotUi[key];
        }

        function paintSlot(round, i, s) {
            var holder = refs[round].teams[i].slots[s];
            holder.textContent = '';
            var value = teams[round][i][s];
            var label = ROUNDS[round].short + ' team ' + (i + 1) + ', player ' + (s + 1);

            if (value) {
                holder.appendChild(pickedChip(round, i, s, value));
                return;
            }
            if (mode === 'readonly') {
                holder.appendChild(el('p', 'text-xs font-bold text-gray-500 py-2', 'Not entered'));
                return;
            }
            var ui = uiOf(round, i, s);
            if (ui.guest) {
                holder.appendChild(guestEntry(round, i, s, label, ui));
            } else {
                holder.appendChild(searchBox(round, i, s, label, ui));
            }
        }

        function pickedChip(round, i, s, value) {
            var row = el('div', 'rf-picked');
            row.appendChild(avatar(value));
            var text = el('div', 'min-w-0 flex-1');
            text.appendChild(el('p', 'text-sm font-black text-white break-words', fullName(value)));
            if (value.kind === 'guest') {
                text.appendChild(el('p', 'text-xs font-bold text-gray-400', 'Guest, no points'));
            } else {
                var sub = [value.kbfId, value.city].filter(Boolean).join(', ');
                if (sub) text.appendChild(el('p', 'text-xs font-bold text-gray-400 break-words', sub));
            }
            row.appendChild(text);
            if (value.kind === 'player') row.appendChild(gradeBox(value.grade));
            if (mode !== 'readonly') {
                var x = el('button', 'rf-x');
                x.type = 'button';
                x.setAttribute('aria-label', 'Remove ' + fullName(value));
                x.appendChild(icon('close', 'w-4 h-4'));
                x.addEventListener('click', function () {
                    teams[round][i][s] = null;
                    var ui = uiOf(round, i, s);
                    ui.guest = false;
                    ui.text = '';
                    changed();
                    paintSlot(round, i, s);
                    paintTeam(round, i);
                    var input = refs[round].teams[i].slots[s].querySelector('input');
                    if (input) input.focus();
                });
                row.appendChild(x);
            }
            return row;
        }

        function guestEntry(round, i, s, label, ui) {
            var wrap = el('div', 'flex gap-2');
            var input = el('input', 'rf-field');
            input.type = 'text';
            input.maxLength = 100;
            input.placeholder = "Guest's name";
            input.setAttribute('aria-label', 'Guest name for ' + label);
            input.value = ui.text || '';
            var add = btn('rf-btn rf-btn-sm', 'Add');
            var cancel = el('button', 'rf-x');
            cancel.type = 'button';
            cancel.setAttribute('aria-label', 'Cancel guest');
            cancel.appendChild(icon('close', 'w-4 h-4'));
            function commit() {
                var name = input.value.replace(/\s+/g, ' ').trim().slice(0, 100);
                if (!name) { input.focus(); return; }
                teams[round][i][s] = { kind: 'guest', name: name };
                ui.guest = false;
                ui.text = '';
                changed();
                paintSlot(round, i, s);
                paintTeam(round, i);
                focusAfterPick(round, i, s);
            }
            function stop() {
                ui.guest = false;
                ui.text = '';
                paintSlot(round, i, s);
                paintTeam(round, i);
                var box = refs[round].teams[i].slots[s].querySelector('input');
                if (box) box.focus();
            }
            input.addEventListener('input', function () { ui.text = input.value; });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); commit(); }
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); stop(); }
            });
            add.addEventListener('click', commit);
            cancel.addEventListener('click', stop);
            wrap.appendChild(input);
            wrap.appendChild(add);
            wrap.appendChild(cancel);
            setTimeout(function () { if (ui.focusMe) { ui.focusMe = false; input.focus(); } }, 0);
            return wrap;
        }

        function startGuest(round, i) {
            var team = teams[round][i];
            var s = team[0] ? (team[1] ? -1 : 1) : 0;
            if (s === -1) return;
            var ui = uiOf(round, i, s);
            ui.guest = true;
            ui.focusMe = true;
            paintSlot(round, i, s);
        }

        // Ids already chosen in this category, except one slot
        function chosenIds(exceptRound, exceptI, exceptS) {
            var ids = {};
            ROUND_ORDER.forEach(function (r) {
                teams[r].forEach(function (team, i) {
                    team.forEach(function (v, s) {
                        if (v && v.kind === 'player' && !(r === exceptRound && i === exceptI && s === exceptS)) ids[v.id] = true;
                    });
                });
            });
            return ids;
        }

        // The chosen players (other than this slot) whose ids are in taken
        function chosenPlayers(taken) {
            var list = [];
            ROUND_ORDER.forEach(function (r) {
                teams[r].forEach(function (team) {
                    team.forEach(function (v) {
                        if (v && v.kind === 'player' && taken[v.id] && list.indexOf(v) === -1) list.push(v);
                    });
                });
            });
            return list;
        }

        function searchBox(round, i, s, label, ui) {
            var wrap = el('div', 'relative');
            var iconEl = icon('search', 'w-4 h-4 text-gray-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none');
            var input = el('input', 'rf-field pl-9');
            var listId = nextId('rfList');
            input.type = 'text';
            input.autocomplete = 'off';
            input.placeholder = 'Search name or KBF ID';
            input.maxLength = 100;
            input.value = ui.text || '';
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', listId);
            input.setAttribute('aria-label', label);
            var list = el('ul', 'rf-list hidden');
            list.id = listId;
            list.setAttribute('role', 'listbox');
            list.setAttribute('aria-label', 'Players');
            var note = el('p', 'hidden text-xs font-bold rf-warn mt-1');
            note.setAttribute('role', 'status');
            wrap.appendChild(iconEl);
            wrap.appendChild(input);
            wrap.appendChild(list);
            var outer = el('div');
            outer.appendChild(wrap);
            outer.appendChild(note);

            var options = [];   // [{node, pick}]
            var active = -1;

            function close() {
                list.classList.add('hidden');
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                active = -1;
            }

            function setActive(n) {
                options.forEach(function (o, idx) {
                    o.node.classList.toggle('is-active', idx === n);
                    o.node.setAttribute('aria-selected', idx === n ? 'true' : 'false');
                });
                active = n;
                if (n >= 0 && options[n]) {
                    input.setAttribute('aria-activedescendant', options[n].node.id);
                    options[n].node.scrollIntoView({ block: 'nearest' });
                } else {
                    input.removeAttribute('aria-activedescendant');
                }
            }

            function addOption(node, pick) {
                node.id = nextId('rfOpt');
                node.setAttribute('role', 'option');
                node.setAttribute('aria-selected', 'false');
                node.addEventListener('mousedown', function (e) { e.preventDefault(); });
                node.addEventListener('click', pick);
                list.appendChild(node);
                options.push({ node: node, pick: pick });
            }

            // Players are searched on the server (at most 8) a moment after
            // typing stops; an older answer is ignored
            var searchTimer = null;
            var searchSeq = 0;

            function update() {
                var raw = input.value.trim();
                var q = raw.toLowerCase();
                note.classList.add('hidden');
                clearTimeout(searchTimer);
                var seq = ++searchSeq;
                if (!q) {
                    list.textContent = '';
                    options = [];
                    active = -1;
                    close();
                    return;
                }

                var taken = chosenIds(round, i, s);
                // Typed the KBF ID of someone already in this category
                chosenPlayers(taken).forEach(function (p) {
                    if (String(p.kbfId || '').toLowerCase() === q) {
                        note.textContent = fullName(p) + ' (' + p.kbfId + ') is already in this category.';
                        note.classList.remove('hidden');
                    }
                });

                searchTimer = setTimeout(function () {
                    searchPlayers(raw, taken).then(function (found) {
                        if (seq !== searchSeq || input.value.trim() !== raw || document.activeElement !== input) return;
                        var takenNow = chosenIds(round, i, s);
                        showOptions(raw, found.filter(function (p) { return !takenNow[p.id]; }));
                    });
                }, 200);
            }

            function showOptions(raw, found) {
                list.textContent = '';
                options = [];
                active = -1;
                found.forEach(function (p) {
                    var li = el('li', 'rf-option');
                    li.appendChild(avatar(p));
                    var text = el('div', 'min-w-0 flex-1');
                    text.appendChild(el('p', 'text-sm font-black text-white break-words', fullName(p)));
                    var sub = [p.kbfId, p.city].filter(Boolean).join(', ');
                    if (sub) text.appendChild(el('p', 'text-xs font-bold text-gray-400 break-words', sub));
                    li.appendChild(text);
                    li.appendChild(gradeBox(p.grade));
                    addOption(li, function () { pick(p); });
                });
                if (!options.length) {
                    var none = el('li', 'px-3 py-2 text-xs font-bold text-gray-400', 'No registered player matches.');
                    none.setAttribute('role', 'presentation');
                    list.appendChild(none);
                }
                var guest = el('li', 'rf-option is-guest');
                guest.appendChild(icon('plus', 'w-4 h-4 flex-shrink-0'));
                guest.appendChild(document.createTextNode('Not registered? Add them as a guest.'));
                addOption(guest, function () {
                    ui.guest = true;
                    ui.text = raw;
                    ui.focusMe = true;
                    close();
                    paintSlot(round, i, s);
                });
                list.classList.remove('hidden');
                input.setAttribute('aria-expanded', 'true');
            }

            function pick(p) {
                teams[round][i][s] = p;
                ui.text = '';
                close();
                changed();
                paintSlot(round, i, s);
                paintTeam(round, i);
                focusAfterPick(round, i, s);
            }

            input.addEventListener('input', function () { ui.text = input.value; update(); });
            input.addEventListener('focus', function () { if (input.value.trim()) update(); });
            input.addEventListener('blur', function () { setTimeout(close, 120); });
            input.addEventListener('keydown', function (e) {
                var open = !list.classList.contains('hidden');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (!open) { update(); }
                    if (options.length) setActive(active + 1 >= options.length ? 0 : active + 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!open) { update(); }
                    if (options.length) setActive(active <= 0 ? options.length - 1 : active - 1);
                } else if (e.key === 'Enter') {
                    if (open && active >= 0 && options[active]) {
                        e.preventDefault();
                        options[active].pick();
                    } else if (open && options.length > 1) {
                        // Enter with nothing highlighted picks the first player
                        e.preventDefault();
                        options[0].pick();
                    } else {
                        e.preventDefault();
                    }
                } else if (e.key === 'Escape') {
                    if (open) {
                        e.preventDefault();
                        e.stopPropagation();
                        close();
                    } else if (input.value) {
                        e.preventDefault();
                        input.value = '';
                        ui.text = '';
                        note.classList.add('hidden');
                    }
                } else if (e.key === 'Tab') {
                    close();
                }
            });
            return outer;
        }

        // After choosing: the other player of the team, or the chip's remove button
        function focusAfterPick(round, i, s) {
            var other = 1 - s;
            var target = null;
            if (!teams[round][i][other]) {
                target = refs[round].teams[i].slots[other].querySelector('input');
            }
            if (!target) target = refs[round].teams[i].slots[s].querySelector('button');
            if (target) target.focus();
        }

        // ============================================
        // CHECKING
        // ============================================
        function filledCount(team) {
            return (team[0] ? 1 : 0) + (team[1] ? 1 : 0);
        }

        function teamName(round, i) {
            return ROUNDS[round].short + ' team ' + (i + 1);
        }

        function capital(text) {
            return text.charAt(0).toUpperCase() + text.slice(1);
        }

        function problems() {
            var blocking = [];
            var warnings = [];
            if (!current) blocking.push('Choose the category these results are for.');
            teams.final.forEach(function (team, i) {
                if (filledCount(team) < 2) blocking.push('Final team ' + (i + 1) + ' needs two players.');
            });
            if (!winner) blocking.push('Mark the winners of the final.');
            ['semi', 'quarter'].forEach(function (r) {
                teams[r].forEach(function (team, i) {
                    if (filledCount(team) === 1) warnings.push(capital(teamName(r, i)) + ' has only one player.');
                });
            });
            return { blocking: blocking, warnings: warnings };
        }

        function stats() {
            var chosen = 0;
            var registered = 0;
            var total = 0;
            ROUND_ORDER.forEach(function (r) {
                teams[r].forEach(function (team, i) {
                    var pts = pointsOf(r, i);
                    if (pts === null) pts = data.points.runnersUp;
                    team.forEach(function (v) {
                        if (!v) return;
                        chosen++;
                        if (v.kind === 'player' && pts > 0) {
                            registered++;
                            total += pts;
                        }
                    });
                });
            });
            return { chosen: chosen, registered: registered, total: total };
        }

        // ---------- career points only (the server decides) ----------
        function registeredIds() {
            var ids = [];
            ROUND_ORDER.forEach(function (r) {
                teams[r].forEach(function (team) {
                    team.forEach(function (v) { if (v && v.kind === 'player' && ids.indexOf(v.id) === -1) ids.push(v.id); });
                });
            });
            return ids.sort(function (a, b) { return a - b; });
        }

        function scheduleNotes() {
            if (mode === 'readonly' || !current) return;
            var ids = registeredIds();
            var key = ids.join(',');
            if (key === notesKey) return;
            clearTimeout(notesTimer);
            notesTimer = setTimeout(function () {
                notesKey = key;
                if (!ids.length) {
                    careerNotes = {};
                    refresh();
                    return;
                }
                request(API + '?action=pointsNotes&tournamentId=' + encodeURIComponent(String(tid)) + '&players=' + encodeURIComponent(key))
                    .then(function (res) {
                        if (key !== notesKey || !res.success) return;
                        careerNotes = {};
                        (res.notes || []).forEach(function (n) { careerNotes[n.id] = n; });
                        refresh();
                    })
                    .catch(function () { /* notes are for information only */ });
            }, 350);
        }

        // "Priya Shah was promoted on 3 May 2026. These 50 points go to career points only."
        function careerNote(player, pts) {
            var n = player && player.kind === 'player' ? careerNotes[player.id] : null;
            if (!n || !pts) return '';
            var d = parseDate(n.date);
            var when = KBF.formatDate(d, { fallback: n.date });
            var tail = 'These ' + pts + ' points go to career points only.';
            if (n.kind === 'promotion') return fullName(player) + ' was promoted on ' + when + '. ' + tail;
            if (n.kind === 'grade') return fullName(player) + ' moved to grade ' + (n.grade || 'another grade') + ' on ' + when + '. ' + tail;
            return fullName(player) + ': This tournament was in last season. ' + tail;
        }

        // Every career-only note in the form, in round order
        function careerNoteList() {
            var out = [];
            ['final', 'semi', 'quarter'].forEach(function (r) {
                teams[r].forEach(function (team, i) {
                    var pts = pointsOf(r, i);
                    team.forEach(function (v) {
                        var text = careerNote(v, pts);
                        if (text) out.push(text);
                    });
                });
            });
            return out;
        }

        function mainVerb() {
            if (mode === 'edit') return 'save';
            return data.autoApprove ? 'publish' : 'submit';
        }

        function refresh() {
            var st = stats();
            checkHeading.textContent = mode === 'edit' ? 'Before you save' : (data.autoApprove ? 'Before you publish' : 'Before you submit');
            checkStats.textContent = st.chosen + ' of 16 players chosen. Points go to ' + plural(st.registered, 'player') +
                ', ' + st.total.toLocaleString('en-GB') + ' points in total';
            checkProblems.textContent = '';
            if (mode !== 'readonly') {
                var pr = problems();
                pr.blocking.forEach(function (text) {
                    var li = el('li', 'flex items-start gap-2 text-sm font-bold text-gray-200');
                    li.appendChild(icon('info', 'w-4 h-4 flex-shrink-0 mt-0.5 text-gray-400'));
                    li.appendChild(document.createTextNode(text));
                    checkProblems.appendChild(li);
                });
                pr.warnings.forEach(function (text) {
                    var li = el('li', 'flex items-start gap-2 text-sm font-bold rf-warn');
                    li.appendChild(icon('warn', 'w-4 h-4 flex-shrink-0 mt-0.5'));
                    li.appendChild(document.createTextNode(text));
                    checkProblems.appendChild(li);
                });
                if (!pr.blocking.length && !pr.warnings.length) {
                    var ok = el('li', 'flex items-start gap-2 text-sm font-bold theme-primary-text');
                    ok.appendChild(icon('check', 'w-4 h-4 flex-shrink-0 mt-0.5'));
                    ok.appendChild(document.createTextNode('Ready to ' + mainVerb() + '.'));
                    checkProblems.appendChild(ok);
                }
                mainBtn.disabled = busy || pr.blocking.length > 0 || draftPending;
                careerNoteList().forEach(function (text) {
                    var li = el('li', 'flex items-start gap-2 text-sm font-semibold text-gray-400');
                    li.appendChild(icon('info', 'w-4 h-4 flex-shrink-0 mt-0.5'));
                    li.appendChild(document.createTextNode(text));
                    checkProblems.appendChild(li);
                });
            }
            checkDraft.classList.toggle('hidden', !(mode === 'enter' && draftSaved));
            scheduleNotes();
        }

        function changed() {
            dirty = true;
            saveDraft();
            refresh();
        }

        function renderActions() {
            var readonly = mode === 'readonly';
            mainBtn.classList.toggle('hidden', readonly);
            checkBtn.classList.toggle('hidden', false);
            mainBtn.lastChild.textContent = mode === 'edit' ? 'Save changes' : (data.autoApprove ? 'Publish results' : 'Submit for approval');
            mainNote.classList.toggle('hidden', !(mode === 'enter' && !data.autoApprove));
            deleteBtn.classList.toggle('hidden', !(mode === 'edit' && current && current.canDelete && current.state === 'waiting'));
        }

        // ============================================
        // STEPS (smaller screens)
        // ============================================
        function applyStep() {
            var name = STEPS[step];
            ROUND_ORDER.forEach(function (r) {
                if (refs[r]) refs[r].section.classList.toggle('rf-step-hidden', r !== name);
            });
            checkPanel.classList.toggle('rf-step-hidden', name !== 'check');
            actions.classList.toggle('rf-step-hidden', name !== 'check');
            mainNote.classList.toggle('rf-step-hidden', name !== 'check');
            stepBar.textContent = 'Step ' + (step + 1) + ' of ' + STEPS.length + ': ' + STEP_TITLES[name];
            stepBack.disabled = step === 0;
            stepNext.disabled = step === STEPS.length - 1;
        }
        stepBack.addEventListener('click', function () { if (step > 0) { step--; applyStep(); stepBar.scrollIntoView({ block: 'nearest' }); } });
        stepNext.addEventListener('click', function () { if (step < STEPS.length - 1) { step++; applyStep(); stepBar.scrollIntoView({ block: 'nearest' }); } });

        // ============================================
        // SUMMARY ("Check results")
        // ============================================
        function slotLine(v, pts) {
            var row = el('li', 'flex items-center justify-between gap-3 py-1');
            if (!v) {
                row.appendChild(el('span', 'text-sm font-bold text-gray-500', 'Not entered'));
                return row;
            }
            row.appendChild(el('span', 'text-sm font-black text-white break-words', fullName(v) + (v.kind === 'player' && v.kbfId ? ' (' + v.kbfId + ')' : '')));
            row.appendChild(el('span', 'text-xs font-black whitespace-nowrap ' + (v.kind === 'guest' ? 'text-gray-400' : 'theme-primary-text'),
                v.kind === 'guest' ? 'Guest, no points' : (pts === null ? 'Winners not marked' : pts + ' pts')));
            return row;
        }

        function openSummary() {
            var m = modalShell('Check results' + (current && current.name ? ': ' + current.name : ''));
            var content = el('div', 'space-y-4 max-h-[60vh] overflow-y-auto pr-1');
            ['final', 'semi', 'quarter'].forEach(function (r) {
                var section = el('section');
                section.appendChild(el('h3', 'text-xs font-black uppercase theme-primary-text mb-1', ROUNDS[r].title));
                teams[r].forEach(function (team, i) {
                    var box = el('div', 'border-2 border-gray-700 p-2 mb-2');
                    var label = teamLabel(r, i) + (r === 'final' && winner === i + 1 ? ' (winners)' : (r === 'final' && winner ? ' (runners-up)' : ''));
                    box.appendChild(el('p', 'text-xs font-black uppercase text-gray-300', label));
                    var ul = el('ul');
                    var pts = pointsOf(r, i);
                    [team[0], team[1]].forEach(function (v) {
                        ul.appendChild(slotLine(v, pts));
                        var note = careerNote(v, pts);
                        if (note) ul.appendChild(el('li', 'text-xs font-semibold text-gray-400 pb-1', note));
                    });
                    box.appendChild(ul);
                    section.appendChild(box);
                });
                content.appendChild(section);
            });
            m.box.appendChild(content);
            var st = stats();
            m.box.appendChild(el('p', 'text-sm font-bold text-gray-200 mt-4', st.chosen + ' of 16 players chosen. Points go to ' + plural(st.registered, 'player') + ', ' + st.total.toLocaleString('en-GB') + ' points in total'));
            var row = el('div', 'flex flex-col-reverse sm:flex-row sm:justify-end gap-2 mt-5');
            var close = btn('rf-btn', 'Close');
            row.appendChild(close);
            if (mode !== 'readonly') {
                var go = btn('rf-btn rf-btn-solid', mainBtn.lastChild.textContent);
                go.disabled = mainBtn.disabled;
                go.addEventListener('click', function () { closeModal(m.wrap); save(); });
                row.appendChild(go);
            }
            m.box.appendChild(row);
            close.addEventListener('click', function () { closeModal(m.wrap); });
            m.wrap.addEventListener('click', function (e) { if (e.target === m.wrap) closeModal(m.wrap); });
            openModal(m.wrap, function () { closeModal(m.wrap); });
        }
        checkBtn.addEventListener('click', openSummary);

        // ============================================
        // SAVING
        // ============================================
        // Guests are sent as { guest: name } (stored as a name whatever its
        // text, numbers included); only a chosen player is sent as { userId }
        function slotValue(v) {
            if (!v) return null;
            if (v.kind === 'guest') return { guest: String(v.name) };
            var id = parseInt(v.id, 10);
            return id > 0 ? { userId: id } : null;
        }

        function match(round, a, b, w) {
            return {
                roundType: ROUNDS[round].apiRound,
                player1Id: slotValue(a[0]), partner1Id: slotValue(a[1]),
                player2Id: slotValue(b[0]), partner2Id: slotValue(b[1]),
                winnerTeam: w, team1Score: 0, team2Score: 0
            };
        }

        function buildMatches() {
            var list = [match('final', teams.final[0], teams.final[1], winner)];
            if (filledCount(teams.semi[0]) + filledCount(teams.semi[1]) > 0) {
                list.push(match('semi', teams.semi[0], teams.semi[1], 0));
            }
            if (filledCount(teams.quarter[0]) + filledCount(teams.quarter[1]) > 0) {
                list.push(match('quarter', teams.quarter[0], teams.quarter[1], 0));
            }
            if (filledCount(teams.quarter[2]) + filledCount(teams.quarter[3]) > 0) {
                list.push(match('quarter', teams.quarter[2], teams.quarter[3], 0));
            }
            return list;
        }

        // "You have not entered the quarter-final teams 2, 3 and 4 ..."
        function gapsMessage() {
            var empty = [];
            var partial = [];
            ['semi', 'quarter'].forEach(function (r) {
                var none = [];
                teams[r].forEach(function (team, i) {
                    var n = filledCount(team);
                    if (n === 0) none.push(String(i + 1));
                    if (n === 1) partial.push(teamName(r, i));
                });
                if (none.length) {
                    empty.push('the ' + ROUNDS[r].short + (none.length === 1 ? ' team ' : ' teams ') + listAnd(none));
                }
            });
            if (!empty.length && !partial.length) return null;
            var parts = [];
            if (empty.length) parts.push('You have not entered ' + listAnd(empty) + '.');
            if (partial.length) parts.push('Only one player is entered for ' + listAnd(partial) + '.');
            var verb = mainVerb();
            var question = verb === 'save' ? 'Do you still want to save?' : (verb === 'publish' ? 'Do you still want to publish?' : 'Do you still want to submit them?');
            parts.push(question + ' You can add or change results later.');
            return parts.join(' ');
        }

        function save() {
            if (busy || mode === 'readonly' || !current) return;
            var pr = problems();
            if (pr.blocking.length) {
                showMsg(pr.blocking[0], 'error');
                return;
            }
            var gaps = gapsMessage();
            var verb = mainVerb();
            var anyway = verb === 'save' ? 'Save anyway' : (verb === 'publish' ? 'Publish anyway' : 'Submit anyway');
            var confirmed = gaps ? askConfirm('Some teams are not complete', gaps, anyway, 'Go back') : Promise.resolve(true);
            confirmed.then(function (ok) {
                if (!ok) return;
                send();
            });
        }

        function send() {
            busy = true;
            mainBtn.disabled = true;
            mainBtn.setAttribute('aria-busy', 'true');
            mainBtn.lastChild.textContent = mode === 'edit' ? 'Saving...' : (data.autoApprove ? 'Publishing...' : 'Submitting...');
            showMsg('');
            var savedCategory = current;
            var isEdit = mode === 'edit';
            var payload = { tournamentId: tid, categoryName: current.name, matches: buildMatches() };
            var headers = { 'Content-Type': 'application/json' };
            // New results: a one-time id, so a repeat of this request (double
            // tap, retry after a lost connection) never saves them twice
            var requestKey = 'results:' + tid + ':' + savedCategory.key;
            var forms = window.KBF && window.KBF.requestId ? window.KBF : null;
            if (!isEdit && forms) headers[forms.REQUEST_ID_HEADER] = forms.requestId(requestKey);
            request(API, {
                method: isEdit ? 'PUT' : 'POST',
                headers: headers,
                body: JSON.stringify(payload)
            }).then(function (res) {
                busy = false;
                mainBtn.removeAttribute('aria-busy');
                renderActions();
                if (res.success && !isEdit && forms) forms.clearRequestId(requestKey);
                if (!res.success) {
                    if (res._status === 401) {
                        showMsg('Your session has expired. Log in again in a new tab, then press the button again. Your results are still here.', 'error');
                    } else {
                        showMsg(res.message || 'The results could not be saved.', 'error');
                    }
                    refresh();
                    return;
                }
                if (!isEdit) removeDraft();
                dirty = false;
                var message = res.message || 'Results saved.';
                return load(savedCategory.key, false).then(function (okLoad) {
                    if (!okLoad) return;
                    if (!isEdit) {
                        var next = categories.filter(function (c) { return c.state === 'none' && c.key !== savedCategory.key; })[0];
                        if (next) {
                            selectCategory(next.key, true);
                            message += ' Now entering ' + next.name + '.';
                        }
                    }
                    showMsg(message, 'ok');
                    pageMsg.scrollIntoView({ block: 'nearest' });
                });
            }).catch(function () {
                busy = false;
                mainBtn.removeAttribute('aria-busy');
                renderActions();
                showMsg('The results could not be saved. Please check your connection and try again.', 'error');
                refresh();
            });
        }
        mainBtn.addEventListener('click', save);

        deleteBtn.addEventListener('click', function () {
            if (!current || busy) return;
            var cat = current;
            askConfirm('Delete these results?', 'Delete the results for ' + (cat.name || 'this category') + '? They have not been approved yet. This cannot be undone.', 'Delete', 'Go back').then(function (ok) {
                if (!ok) return;
                busy = true;
                request(API + '?tournamentId=' + encodeURIComponent(String(tid)) + '&category=' + encodeURIComponent(cat.name), { method: 'DELETE' }).then(function (res) {
                    busy = false;
                    if (!res.success) {
                        showMsg(res.message || 'The results could not be deleted.', 'error');
                        return;
                    }
                    dirty = false;
                    return load(cat.key, false).then(function () {
                        showMsg(res.message || 'The results were deleted.', 'ok');
                    });
                }).catch(function () {
                    busy = false;
                    showMsg('The results could not be deleted.', 'error');
                });
            });
        });

        window.addEventListener('beforeunload', function (e) {
            if (dirty && mode === 'edit') {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        if (!tid) {
            loading.textContent = 'No tournament was chosen.';
            return;
        }
        load(null, true);
    }

    window.KBFResultsForm = { mount: mount };
})();
