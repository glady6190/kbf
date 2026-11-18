/* ============================================
 * KBF-UK: CHANGE A GRADE DIALOG
 * One dialog for the Grading page and the People panel. Saves through
 * api/rankings.php (players.change_grades).
 *
 *   KBFGradeChange.open({
 *       userId: 12,              // optional: start with this player chosen
 *       player: {...},           // optional: a player row from api/rankings.php
 *       newGradeId: 3,           // optional: start with this grade chosen
 *       reason: '...',           // optional: starting reason
 *       meta: {...},             // optional: grades and defaults (else fetched)
 *       onDone: function (result) { ... }   // after a successful change
 *   });
 *
 * Season points rule (api/rankings.php and api/points-rules.php): a grade
 * change sets season points to 0, and tournaments on or before the day of the
 * change count toward career points only.
 * ============================================ */
(function () {
    'use strict';

    var API = '/api/rankings.php';
    var STYLE_ID = 'kgc-styles';
    var ICONS = {
        close: 'M6 18L18 6M6 6l12 12',
        search: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
        arrow: 'M13 7l5 5m0 0l-5 5m5-5H6'
    };

    var state = null;
    var metaCache = null;
    var host = null;
    var searchTimer = null;
    var searchSeq = 0;

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var s = document.createElement('style');
        s.id = STYLE_ID;
        s.textContent =
            '.kgc-wrap.hidden,.kgc .hidden{display:none !important;}' +
            '.kgc-wrap{position:fixed;inset:0;background:rgba(2,6,23,.84);z-index:70;display:flex;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto;}' +
            '@media (min-width:640px){.kgc-wrap{align-items:center;}}' +
            '.kgc{background:#0f172a;border:4px solid #374151;width:100%;max-width:36rem;margin:2rem 0;color:#e5e7eb;}' +
            '.kgc-label{display:block;font-size:.7rem;font-weight:900;text-transform:uppercase;color:var(--theme-primary);margin-bottom:.4rem;}' +
            '.kgc-field{width:100%;background:#1e293b;border:2px solid #374151;color:#fff;padding:.6rem .75rem;font-weight:700;font-size:.8125rem;}' +
            '.kgc-field:focus{outline:none;border-color:var(--theme-primary);}' +
            '.kgc-field[aria-invalid="true"]{border-color:#ef4444;}' +
            '.kgc-results{border:2px solid #374151;border-top:0;max-height:16rem;overflow-y:auto;background:#0f172a;}' +
            '.kgc-option{display:flex;align-items:center;gap:.75rem;width:100%;text-align:left;padding:.55rem .75rem;border-bottom:2px solid #1e293b;}' +
            '.kgc-option:hover,.kgc-option:focus,.kgc-option[aria-selected="true"]{background:#1e293b;outline:none;}' +
            '.kgc-avatar{width:2.5rem;height:2.5rem;flex-shrink:0;border:2px solid #374151;background:#1e293b;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.8rem;color:#9ca3af;overflow:hidden;}' +
            '.kgc-avatar img{width:100%;height:100%;object-fit:cover;}' +
            '.kgc-chip{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;background:#1e293b;border:2px solid var(--theme-primary);}' +
            '.kgc-grade{min-width:3rem;padding:.5rem .75rem;background:#1e293b;border:2px solid #374151;color:#d1d5db;font-weight:900;font-size:.8rem;text-transform:uppercase;}' +
            '.kgc-grade:hover:not(:disabled){border-color:#6b7280;color:#fff;}' +
            '.kgc-grade[aria-pressed="true"]{background:var(--theme-primary);border-color:var(--theme-primary);color:#0F172A;}' +
            '.kgc-grade:disabled{opacity:.35;cursor:not-allowed;text-decoration:line-through;}' +
            '.kgc-sq{display:inline-flex;align-items:center;justify-content:center;min-width:2.25rem;height:2.25rem;padding:0 .4rem;font-weight:900;font-size:.85rem;}' +
            '.kgc-sq-old{border:2px solid #6b7280;color:#e5e7eb;}' +
            '.kgc-sq-new{background:var(--theme-primary);border:2px solid var(--theme-primary);color:#0F172A;}' +
            '.kgc-effect{border:2px solid #374151;background:#1e293b;padding:.75rem;}' +
            '.kgc-tick{display:flex;align-items:flex-start;gap:.65rem;padding:.6rem .75rem;background:#1e293b;border:2px solid #374151;cursor:pointer;}' +
            '.kgc-tick input{accent-color:var(--theme-primary);width:18px;height:18px;flex-shrink:0;margin-top:.05rem;}' +
            '.kgc-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;font-weight:900;text-transform:uppercase;font-size:.75rem;padding:.7rem 1.1rem;white-space:nowrap;}' +
            '.kgc-solid{background:var(--theme-primary);color:#0F172A;border:2px solid var(--theme-primary);box-shadow:5px 5px 0 #020617;}' +
            '.kgc-solid:disabled{opacity:.4;cursor:not-allowed;box-shadow:none;}' +
            '.kgc-plain{background:#1e293b;color:#e5e7eb;border:2px solid #374151;}' +
            '.kgc-plain:hover{border-color:#6b7280;color:#fff;}' +
            '.kgc-small{padding:.35rem .6rem;font-size:.65rem;}' +
            '.kgc-btn:focus-visible,.kgc-grade:focus-visible,.kgc-option:focus-visible{outline:2px solid #fff;outline-offset:2px;}' +
            '.kgc-msg{padding:.75rem;border:2px solid #ef4444;color:#fca5a5;background:rgba(239,68,68,.12);font-weight:700;font-size:.75rem;}';
        document.head.appendChild(s);
    }

    function svg(name, cls) {
        var ns = 'http://www.w3.org/2000/svg';
        var el = document.createElementNS(ns, 'svg');
        el.setAttribute('class', cls || 'w-4 h-4');
        el.setAttribute('width', '16');
        el.setAttribute('height', '16');
        el.setAttribute('fill', 'none');
        el.setAttribute('stroke', 'currentColor');
        el.setAttribute('viewBox', '0 0 24 24');
        el.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path');
        p.setAttribute('stroke-linecap', 'round');
        p.setAttribute('stroke-linejoin', 'round');
        p.setAttribute('stroke-width', '2.5');
        p.setAttribute('d', ICONS[name]);
        el.appendChild(p);
        return el;
    }

    function el(tag, className, text) {
        var n = document.createElement(tag);
        if (className) n.className = className;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function csrf() {
        try { return sessionStorage.getItem('csrf_token'); } catch (e) { return null; }
    }

    function request(url, options) {
        options = options || {};
        options.credentials = 'include';
        options.headers = options.headers || {};
        var t = csrf();
        if (t) options.headers['X-CSRF-Token'] = t;
        return fetch(url, options).then(function (r) { return r.json(); });
    }

    function fmt(n) { return Number(n || 0).toLocaleString('en-GB'); }

    function avatar(p) {
        var box = el('span', 'kgc-avatar');
        if (p.photo) {
            var img = document.createElement('img');
            img.src = p.photo;
            img.alt = '';
            box.appendChild(img);
        } else {
            box.textContent = p.initials || '';
        }
        return box;
    }

    function playerLine(p) {
        var parts = [p.kbfId];
        if (p.city) parts.push(p.city);
        parts.push('Grade ' + (p.grade || 'none'));
        parts.push(fmt(p.points) + ' season points');
        return parts.join(' • ');
    }

    function loadMeta() {
        if (metaCache) return Promise.resolve(metaCache);
        return request(API + '?action=meta').then(function (data) {
            if (!data.success) throw new Error(data.message || 'Could not load the grades');
            metaCache = data;
            return data;
        });
    }

    // ============================================
    // BUILD
    // ============================================
    function build() {
        host = el('div', 'kgc-wrap');
        host.setAttribute('role', 'dialog');
        host.setAttribute('aria-modal', 'true');
        host.setAttribute('aria-labelledby', 'kgcTitle');

        var box = el('form', 'kgc p-4 sm:p-6 space-y-5');
        box.noValidate = true;

        var head = el('div', 'flex items-start justify-between gap-3');
        var title = el('h2', 'text-lg sm:text-xl font-black uppercase', 'Change a grade');
        title.id = 'kgcTitle';
        title.style.color = 'var(--theme-primary)';
        var close = el('button', 'kgc-btn kgc-plain kgc-small');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close');
        close.appendChild(svg('close'));
        close.addEventListener('click', closeDialog);
        head.appendChild(title);
        head.appendChild(close);
        box.appendChild(head);

        // Player
        var playerSection = el('div');
        var pl = el('label', 'kgc-label', 'Player');
        pl.htmlFor = 'kgcSearch';
        pl.id = 'kgcPlayerLabel';
        playerSection.appendChild(pl);
        var searchWrap = el('div', 'relative');
        searchWrap.id = 'kgcSearchWrap';
        var icon = svg('search', 'w-4 h-4 text-gray-500 absolute left-3 top-3');
        searchWrap.appendChild(icon);
        var input = el('input', 'kgc-field pl-9');
        input.id = 'kgcSearch';
        input.type = 'search';
        input.autocomplete = 'off';
        input.maxLength = 100;
        input.placeholder = 'Search by name or KBF ID';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', 'kgcResults');
        input.setAttribute('aria-autocomplete', 'list');
        searchWrap.appendChild(input);
        var results = el('div', 'kgc-results hidden');
        results.id = 'kgcResults';
        results.setAttribute('role', 'listbox');
        results.setAttribute('aria-labelledby', 'kgcPlayerLabel');
        searchWrap.appendChild(results);
        playerSection.appendChild(searchWrap);
        var chip = el('div', 'kgc-chip hidden');
        chip.id = 'kgcChip';
        playerSection.appendChild(chip);
        box.appendChild(playerSection);

        // New grade
        var gradeSection = el('fieldset');
        gradeSection.appendChild(el('legend', 'kgc-label', 'New grade'));
        var gradeRow = el('div', 'flex flex-wrap gap-2');
        gradeRow.id = 'kgcGrades';
        gradeSection.appendChild(gradeRow);
        box.appendChild(gradeSection);

        // Effect
        var effect = el('div', 'kgc-effect hidden');
        effect.id = 'kgcEffect';
        effect.setAttribute('aria-live', 'polite');
        box.appendChild(effect);

        // Reason
        var reasonSection = el('div');
        var rl = el('label', 'kgc-label', 'Reason');
        rl.htmlFor = 'kgcReason';
        reasonSection.appendChild(rl);
        var reason = el('textarea', 'kgc-field');
        reason.id = 'kgcReason';
        reason.rows = 3;
        reason.maxLength = 500;
        reason.placeholder = 'For example: Committee review after the Manchester tournament';
        reason.setAttribute('aria-describedby', 'kgcReasonHint');
        reasonSection.appendChild(reason);
        var hint = el('p', 'text-xs text-gray-400 font-semibold mt-1', '3 to 500 characters. The player sees this in their email.');
        hint.id = 'kgcReasonHint';
        reasonSection.appendChild(hint);
        box.appendChild(reasonSection);

        // Ticks
        var ticks = el('div', 'space-y-2');
        var emailTick = el('label', 'kgc-tick');
        emailTick.id = 'kgcEmailRow';
        var emailBox = el('input');
        emailBox.type = 'checkbox';
        emailBox.id = 'kgcEmail';
        emailTick.appendChild(emailBox);
        var emailText = el('span', 'text-xs sm:text-sm font-bold text-gray-200');
        emailText.id = 'kgcEmailText';
        emailTick.appendChild(emailText);
        ticks.appendChild(emailTick);
        var newsTick = el('label', 'kgc-tick hidden');
        newsTick.id = 'kgcNewsRow';
        var newsBox = el('input');
        newsBox.type = 'checkbox';
        newsBox.id = 'kgcNews';
        newsTick.appendChild(newsBox);
        newsTick.appendChild(el('span', 'text-xs sm:text-sm font-bold text-gray-200', 'Post it on the News page'));
        ticks.appendChild(newsTick);
        box.appendChild(ticks);

        var msg = el('div', 'kgc-msg hidden');
        msg.id = 'kgcMessage';
        msg.setAttribute('role', 'alert');
        box.appendChild(msg);

        var actions = el('div', 'flex flex-col-reverse sm:flex-row sm:justify-end gap-3');
        var cancel = el('button', 'kgc-btn kgc-plain', 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', closeDialog);
        var save = el('button', 'kgc-btn kgc-solid', 'Choose a new grade');
        save.type = 'submit';
        save.id = 'kgcSave';
        actions.appendChild(cancel);
        actions.appendChild(save);
        box.appendChild(actions);

        host.appendChild(box);
        document.body.appendChild(host);

        // Events
        input.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(runSearch, 250);
        });
        input.addEventListener('keydown', function (e) {
            var opts = Array.prototype.slice.call(results.querySelectorAll('.kgc-option'));
            if (e.key === 'ArrowDown' && opts.length) { e.preventDefault(); opts[0].focus(); }
            if (e.key === 'Enter') { e.preventDefault(); if (opts.length === 1) opts[0].click(); }
        });
        results.addEventListener('keydown', function (e) {
            var opts = Array.prototype.slice.call(results.querySelectorAll('.kgc-option'));
            var i = opts.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); if (i < opts.length - 1) opts[i + 1].focus(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); if (i > 0) opts[i - 1].focus(); else input.focus(); }
        });
        reason.addEventListener('input', function () { reason.removeAttribute('aria-invalid'); });
        emailBox.addEventListener('change', function () { state.emailTouched = true; });
        newsBox.addEventListener('change', function () { state.newsTouched = true; });
        box.addEventListener('submit', function (e) { e.preventDefault(); save_(); });
        host.addEventListener('click', function (e) { if (e.target === host) closeDialog(); });
        host.addEventListener('keydown', function (e) {
            // Handled here only, so a page's own Escape and Tab handling
            // (for example the People panel underneath) does not also run
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeDialog(); }
            if (e.key === 'Tab') { e.stopPropagation(); trapFocus(e); }
        });
    }

    function trapFocus(e) {
        var f = Array.prototype.filter.call(
            host.querySelectorAll('button, input, textarea, [tabindex]:not([tabindex="-1"])'),
            function (n) { return !n.disabled && n.offsetParent !== null; });
        if (!f.length) return;
        var first = f[0];
        var last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    function $(id) { return document.getElementById(id); }

    // ============================================
    // PLAYER
    // ============================================
    function runSearch() {
        var q = $('kgcSearch').value.trim();
        var results = $('kgcResults');
        var seq = ++searchSeq;
        if (q.length < 2) {
            results.classList.add('hidden');
            $('kgcSearch').setAttribute('aria-expanded', 'false');
            return;
        }
        request(API + '?action=search&q=' + encodeURIComponent(q)).then(function (data) {
            if (seq !== searchSeq || !state) return;
            results.textContent = '';
            var players = data.success ? data.players : [];
            if (!players.length) {
                results.appendChild(el('p', 'p-3 text-xs text-gray-400 font-bold uppercase', data.success ? 'No players found' : (data.message || 'Search failed')));
            }
            players.forEach(function (p) {
                var opt = el('button', 'kgc-option');
                opt.type = 'button';
                opt.setAttribute('role', 'option');
                opt.appendChild(avatar(p));
                var text = el('span', 'min-w-0');
                text.appendChild(el('span', 'block text-sm font-black text-white truncate', p.name));
                text.appendChild(el('span', 'block text-xs text-gray-400 font-semibold truncate', playerLine(p)));
                opt.appendChild(text);
                opt.addEventListener('click', function () { choosePlayer(p); });
                results.appendChild(opt);
            });
            results.classList.remove('hidden');
            $('kgcSearch').setAttribute('aria-expanded', 'true');
        }).catch(function () {
            if (seq !== searchSeq) return;
            results.textContent = '';
            results.appendChild(el('p', 'p-3 text-xs text-red-400 font-bold', 'Search failed. Please try again.'));
            results.classList.remove('hidden');
        });
    }

    function choosePlayer(p) {
        state.player = p;
        if (state.newGradeId === p.gradeId) state.newGradeId = null;
        $('kgcSearchWrap').classList.add('hidden');
        $('kgcResults').classList.add('hidden');
        var chip = $('kgcChip');
        chip.textContent = '';
        chip.appendChild(avatar(p));
        var text = el('span', 'min-w-0 flex-1');
        text.appendChild(el('span', 'block text-sm font-black text-white truncate', p.name));
        text.appendChild(el('span', 'block text-xs text-gray-400 font-semibold', playerLine(p)));
        chip.appendChild(text);
        var change = el('button', 'kgc-btn kgc-plain kgc-small', 'Change');
        change.type = 'button';
        change.setAttribute('aria-label', 'Change the player');
        change.addEventListener('click', function () {
            state.player = null;
            chip.classList.add('hidden');
            $('kgcSearchWrap').classList.remove('hidden');
            $('kgcSearch').value = '';
            $('kgcSearch').focus();
            render();
        });
        chip.appendChild(change);
        chip.classList.remove('hidden');
        render();
    }

    // ============================================
    // GRADES, EFFECT, TICKS
    // ============================================
    function grade(id) {
        var list = state.meta.grades;
        for (var i = 0; i < list.length; i++) { if (list[i].id === id) return list[i]; }
        return null;
    }

    function kind() {
        if (!state.player || !state.newGradeId) return null;
        var from = grade(state.player.gradeId);
        var to = grade(state.newGradeId);
        if (!to) return null;
        if (!from) return 'across';
        if (to.rank < from.rank) return 'promotion';
        if (to.rank > from.rank) return 'down';
        return 'across';
    }

    function renderGrades() {
        var row = $('kgcGrades');
        row.textContent = '';
        state.meta.grades.forEach(function (g) {
            var b = el('button', 'kgc-grade', g.shortName);
            b.type = 'button';
            b.title = g.name;
            var isCurrent = state.player && state.player.gradeId === g.id;
            b.disabled = !state.player || isCurrent;
            if (isCurrent) b.setAttribute('aria-label', g.shortName + ', current grade');
            b.setAttribute('aria-pressed', state.newGradeId === g.id ? 'true' : 'false');
            b.addEventListener('click', function () {
                state.newGradeId = g.id;
                render();
            });
            row.appendChild(b);
        });
    }

    function renderEffect() {
        var box = $('kgcEffect');
        var k = kind();
        box.textContent = '';
        if (!k) { box.classList.add('hidden'); return; }
        var from = grade(state.player.gradeId);
        var to = grade(state.newGradeId);
        var names = { promotion: 'Promotion', down: 'Moving down', across: 'Moving across' };
        var top = el('div', 'flex flex-wrap items-center gap-3 mb-2');
        top.appendChild(el('span', 'text-xs font-black uppercase text-white', names[k]));
        var move = el('span', 'flex items-center gap-2');
        move.appendChild(el('span', 'kgc-sq kgc-sq-old', from ? from.shortName : (state.player.grade || '-')));
        var arrow = svg('arrow', 'w-4 h-4 text-gray-400');
        move.appendChild(arrow);
        move.appendChild(el('span', 'kgc-sq kgc-sq-new', to.shortName));
        top.appendChild(move);
        box.appendChild(top);
        var pts = state.player.points;
        box.appendChild(el('p', 'text-xs sm:text-sm text-gray-200 font-semibold leading-relaxed',
            'Their season points (' + fmt(pts) + ') go back to 0 in ' + to.shortName +
            ', and only tournaments after today count toward their new grade.'));
        box.appendChild(el('p', 'text-xs sm:text-sm text-gray-400 font-semibold mt-1', 'Their career points stay as they are.'));
        box.classList.remove('hidden');
    }

    function renderTicks() {
        var k = kind();
        var promo = k === 'promotion';
        var d = state.meta.defaults || {};
        var email = promo ? (d.promotionEmail || {}) : (d.changeEmail || {});
        var row = $('kgcEmailRow');
        var box = $('kgcEmail');
        var first = state.player ? state.player.firstName : 'them';
        $('kgcEmailText').textContent = 'Email ' + (first || 'them') + ' about the ' + (promo ? 'promotion' : 'change');
        row.classList.toggle('hidden', email.applies === false);
        box.disabled = !!email.locked;
        if (!state.emailTouched || email.locked) box.checked = !!email.on;

        var news = d.promotionNews || {};
        var newsRow = $('kgcNewsRow');
        newsRow.classList.toggle('hidden', !promo || news.applies === false);
        if (!state.newsTouched) $('kgcNews').checked = !!news.on;
    }

    function renderSave() {
        var b = $('kgcSave');
        var k = kind();
        var to = state.newGradeId ? grade(state.newGradeId) : null;
        if (!state.player) { b.textContent = 'Choose a player'; b.disabled = true; return; }
        if (!k || !to) { b.textContent = 'Choose a new grade'; b.disabled = true; return; }
        b.textContent = (k === 'promotion' ? 'Promote to ' : 'Move to ') + to.shortName;
        b.disabled = state.saving;
    }

    function render() {
        renderGrades();
        renderEffect();
        renderTicks();
        renderSave();
    }

    function showError(text) {
        var m = $('kgcMessage');
        if (!text) { m.classList.add('hidden'); return; }
        m.textContent = text;
        m.classList.remove('hidden');
    }

    // ============================================
    // SAVE
    // ============================================
    function save_() {
        if (!state || state.saving) return;
        showError('');
        var k = kind();
        if (!state.player) { showError('Choose a player.'); $('kgcSearch').focus(); return; }
        if (!k) { showError('Choose the new grade.'); return; }
        var reason = $('kgcReason').value.trim();
        if (reason.length < 3 || reason.length > 500) {
            $('kgcReason').setAttribute('aria-invalid', 'true');
            $('kgcReason').focus();
            showError('Give a reason of 3 to 500 characters.');
            return;
        }
        var emailRow = $('kgcEmailRow');
        var payload = {
            userId: state.player.userId,
            newCategoryId: state.newGradeId,
            reason: reason,
            createAnnouncement: k === 'promotion' && !$('kgcNewsRow').classList.contains('hidden') && $('kgcNews').checked,
            sendEmail: emailRow.classList.contains('hidden') ? null : $('kgcEmail').checked
        };
        state.saving = true;
        renderSave();
        request(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (data) {
            state.saving = false;
            if (!data.success) { showError(data.message || 'The grade could not be changed.'); renderSave(); return; }
            var done = state.onDone;
            var player = state.player;
            closeDialog();
            if (typeof done === 'function') done({ message: data.message, emailStatus: data.emailStatus, isPromotion: data.isPromotion, player: player });
        }).catch(function () {
            state.saving = false;
            showError('The grade could not be changed. Please try again.');
            renderSave();
        });
    }

    // ============================================
    // OPEN AND CLOSE
    // ============================================
    function open(options) {
        options = options || {};
        injectStyles();
        if (!host) build();
        state = {
            meta: null,
            player: null,
            newGradeId: options.newGradeId || null,
            onDone: options.onDone,
            emailTouched: false,
            newsTouched: false,
            saving: false,
            returnFocus: document.activeElement,
            bodyOverflow: document.body.style.overflow
        };
        if (options.meta && options.meta.grades) metaCache = { grades: options.meta.grades, defaults: options.meta.defaults };

        $('kgcSearch').value = '';
        $('kgcResults').classList.add('hidden');
        $('kgcSearchWrap').classList.remove('hidden');
        $('kgcChip').classList.add('hidden');
        $('kgcReason').value = options.reason || '';
        $('kgcReason').removeAttribute('aria-invalid');
        showError('');
        host.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        return loadMeta().then(function (meta) {
            if (!state) return;
            state.meta = meta;
            render();
            if (options.player) {
                choosePlayer(options.player);
                $('kgcReason').focus();
                return;
            }
            if (options.userId) {
                return request(API + '?action=player&id=' + encodeURIComponent(options.userId)).then(function (data) {
                    if (!state || !state.meta) return;
                    if (!data.success) { showError(data.message || 'The player could not be loaded.'); $('kgcSearch').focus(); return; }
                    choosePlayer(data.player);
                    var first = $('kgcGrades').querySelector('button:not(:disabled)');
                    if (first) first.focus();
                });
            }
            $('kgcSearch').focus();
        }).catch(function (err) {
            showError((err && err.message) || 'The dialog could not be loaded.');
        });
    }

    function closeDialog() {
        if (!host) return;
        host.classList.add('hidden');
        document.body.style.overflow = state ? state.bodyOverflow : '';
        var back = state && state.returnFocus;
        state = null;
        if (back && back.focus) back.focus();
    }

    // Grades or switches changed elsewhere: fetch again next time
    function forget() { metaCache = null; }

    window.KBFGradeChange = { open: open, close: closeDialog, forget: forget };
})();
