/*
 * KBF toast messages: short notes that say what an action did.
 *
 *   KBF.toast('Tournament saved.');                    success (theme colour)
 *   KBF.toast('Nothing to export.', 'info');           info (light)
 *   KBF.toast('Saved, but 2 rows were skipped.', 'warning');   amber
 *   KBF.toast('Could not save: ...', 'error');         red, stays until closed
 *
 *   KBF.toast.next(message, type)   show it on the next page instead (for an
 *                                   action that ends by moving to another page)
 *   KBF.toast.email(status)         one sentence about an email, from the
 *                                   server's emailStatus ('' when unknown)
 *   KBF.toast.fromReply(text, reply, type)   text + the email sentence
 *
 * Top right below the header on desktop; bottom centre on phones, above any
 * fixed bottom bar. At most three are shown, newest on top. Success and info
 * close after 5 seconds, warnings after 8, errors stay. The timer pauses
 * while the pointer is over a toast or it has keyboard focus. Toasts never
 * take focus. Needs nothing else on the page; js/escape.js (KBF.storage)
 * is used for the next-page message when it is loaded.
 */
(function (window, document) {
    'use strict';

    var KBF = window.KBF = window.KBF || {};
    if (typeof KBF.toast === 'function') return;

    var MAX = 3;
    var NEXT_KEY = 'kbf-toast-next';
    var TYPES = ['success', 'info', 'warning', 'error'];
    var DELAY = { success: 5000, info: 5000, warning: 8000, error: 0 };

    var ICONS = {
        success: '<path d="M20 6 9 17l-5-5"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        warning: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        error: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>'
    };
    var CLOSE_ICON = '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>';

    function svg(paths, size) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size +
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
            paths + '</svg>';
    }

    function injectStyles() {
        if (document.getElementById('kbf-toast-styles')) return;
        var style = document.createElement('style');
        style.id = 'kbf-toast-styles';
        style.textContent =
            '.kbf-toasts{position:fixed;z-index:95;display:flex;flex-direction:column;gap:.625rem;pointer-events:none;' +
                'top:var(--kbf-toast-top,1rem);right:1rem;width:24rem;max-width:calc(100vw - 2rem);}' +
            '@media (max-width:639.98px){.kbf-toasts{top:auto;right:auto;left:50%;transform:translateX(-50%);' +
                'bottom:calc(var(--kbf-toast-bottom,0px) + 1rem + env(safe-area-inset-bottom,0px));width:calc(100vw - 2rem);' +
                'flex-direction:column-reverse;}}' +
            '.kbf-toast{pointer-events:auto;display:flex;align-items:flex-start;gap:.625rem;padding:.75rem .5rem .75rem .875rem;' +
                'border:3px solid;box-shadow:5px 5px 0 rgba(0,0,0,.45);font-family:inherit;font-weight:700;font-size:.875rem;line-height:1.4;' +
                'text-align:left;transition:opacity .25s ease,transform .25s ease;}' +
            '.kbf-toast-enter{opacity:0;transform:translateX(1.5rem);}' +
            '@media (max-width:639.98px){.kbf-toast-enter{transform:translateY(1rem);}}' +
            '.kbf-toast-leave{opacity:0;}' +
            '.kbf-toast-icon{flex-shrink:0;display:flex;margin-top:.0625rem;}' +
            '.kbf-toast-text{flex:1;min-width:0;padding-top:.0625rem;overflow-wrap:anywhere;white-space:pre-line;}' +
            '.kbf-toast-close{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;' +
                'margin:-.5rem 0 -.5rem 0;background:transparent;border:0;color:inherit;cursor:pointer;opacity:.8;}' +
            '.kbf-toast-close:hover{opacity:1;}' +
            '.kbf-toast-close:focus-visible{outline:2px solid currentColor;outline-offset:-2px;}' +
            '.kbf-toast-success{background:var(--theme-primary,#8B5CF6);border-color:var(--theme-primary,#8B5CF6);color:#0F172A;}' +
            '.kbf-toast-info{background:#F1F5F9;border-color:#CBD5E1;color:#0F172A;}' +
            '.kbf-toast-warning{background:#FBBF24;border-color:#F59E0B;color:#0F172A;}' +
            '.kbf-toast-error{background:#DC2626;border-color:#991B1B;color:#FFFFFF;}' +
            '.kbf-toast-live{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}' +
            '@media (prefers-reduced-motion:reduce){.kbf-toast{transition:none;}.kbf-toast-enter{opacity:1;transform:none;}}';
        (document.head || document.documentElement).appendChild(style);
    }

    var host = null;
    var politeRegion = null;
    var assertiveRegion = null;

    function liveRegion(level) {
        var node = document.createElement('div');
        node.className = 'kbf-toast-live';
        node.setAttribute('aria-live', level);
        node.setAttribute('aria-atomic', 'true');
        node.setAttribute('role', level === 'assertive' ? 'alert' : 'status');
        document.body.appendChild(node);
        return node;
    }

    function ensureHost() {
        injectStyles();
        if (host && document.body.contains(host)) return host;
        host = document.createElement('div');
        host.className = 'kbf-toasts';
        host.id = 'kbf-toasts';
        document.body.appendChild(host);
        // Live regions exist before any text goes into them, so screen
        // readers announce the change
        politeRegion = liveRegion('polite');
        assertiveRegion = liveRegion('assertive');
        return host;
    }

    function reducedMotion() {
        try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; }
    }

    function isPhone() {
        try { return window.matchMedia('(max-width: 639.98px)').matches; } catch (e) { return window.innerWidth < 640; }
    }

    // Desktop: just below the header while it is on screen. Phones: above
    // any bar fixed (or stuck) to the bottom of the screen.
    function place() {
        if (!host) return;
        var vh = window.innerHeight || document.documentElement.clientHeight;
        var top = 16;
        var header = document.getElementById('kbf-admin-header') || document.querySelector('body > header, header.site-header');
        if (header) {
            var nav = header.querySelector('nav') || header;
            var r = nav.getBoundingClientRect();
            if (r.height > 0 && r.bottom > 0 && r.bottom < vh / 2) top = Math.round(r.bottom) + 12;
            // The admin header is drawn after the page loads; leave its room
            else if (r.height === 0 && header.id === 'kbf-admin-header') top = 88;
        }
        host.style.setProperty('--kbf-toast-top', top + 'px');

        var bottom = 0;
        if (isPhone()) {
            var bars = document.querySelectorAll('[data-kbf-bottom-bar], .fixed.bottom-0, .sticky.bottom-0');
            for (var i = 0; i < bars.length; i++) {
                var b = bars[i];
                if (host.contains(b)) continue;
                var pos = window.getComputedStyle(b).position;
                if (pos !== 'fixed' && pos !== 'sticky') continue;
                var br = b.getBoundingClientRect();
                if (br.height > 0 && br.bottom >= vh - 2 && br.top > vh / 2) {
                    bottom = Math.max(bottom, Math.round(vh - br.top));
                }
            }
        }
        host.style.setProperty('--kbf-toast-bottom', bottom + 'px');
    }

    function announce(type, text) {
        var region = type === 'error' ? assertiveRegion : politeRegion;
        if (!region) return;
        // Clear first so the same message twice is read twice
        region.textContent = '';
        window.setTimeout(function () { region.textContent = text; }, 60);
    }

    function removeToast(node, quick) {
        if (!node || node.__kbfGone) return;
        node.__kbfGone = true;
        if (node.__kbfTimer) window.clearTimeout(node.__kbfTimer);
        // Keep keyboard users in place when the toast they are on closes
        var hadFocus = node.contains(document.activeElement);
        function done() {
            if (node.parentNode) node.parentNode.removeChild(node);
            if (hadFocus && document.body) {
                var next = host && host.querySelector('.kbf-toast-close');
                if (next) next.focus();
            }
        }
        if (quick || reducedMotion()) { done(); return; }
        node.classList.add('kbf-toast-leave');
        window.setTimeout(done, 260);
    }

    function startTimer(node) {
        if (!node.__kbfDelay || node.__kbfGone) return;
        if (node.__kbfTimer) window.clearTimeout(node.__kbfTimer);
        node.__kbfStarted = Date.now();
        node.__kbfTimer = window.setTimeout(function () { removeToast(node); }, node.__kbfLeft);
    }

    function pauseTimer(node) {
        if (!node.__kbfDelay || !node.__kbfTimer) return;
        window.clearTimeout(node.__kbfTimer);
        node.__kbfTimer = null;
        node.__kbfLeft = Math.max(1500, node.__kbfLeft - (Date.now() - node.__kbfStarted));
    }

    function toast(message, type) {
        var text = String(message == null ? '' : message).trim();
        if (!text) return null;
        type = TYPES.indexOf(type) === -1 ? 'success' : type;
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', function () { toast(text, type); });
            return null;
        }
        ensureHost();
        place();

        var node = document.createElement('div');
        node.className = 'kbf-toast kbf-toast-' + type + (reducedMotion() ? '' : ' kbf-toast-enter');
        node.setAttribute('data-kbf-toast', type);

        var icon = document.createElement('span');
        icon.className = 'kbf-toast-icon';
        icon.innerHTML = svg(ICONS[type], 20);
        var body = document.createElement('div');
        body.className = 'kbf-toast-text';
        body.textContent = text;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'kbf-toast-close';
        close.setAttribute('aria-label', 'Close message');
        close.innerHTML = svg(CLOSE_ICON, 18);
        close.addEventListener('click', function () { removeToast(node); });

        node.appendChild(icon);
        node.appendChild(body);
        node.appendChild(close);

        node.__kbfDelay = DELAY[type];
        node.__kbfLeft = DELAY[type];
        node.__kbfHover = false;
        node.__kbfFocus = false;
        node.addEventListener('mouseenter', function () { node.__kbfHover = true; pauseTimer(node); });
        node.addEventListener('mouseleave', function () { node.__kbfHover = false; if (!node.__kbfFocus) startTimer(node); });
        node.addEventListener('focusin', function () { node.__kbfFocus = true; pauseTimer(node); });
        node.addEventListener('focusout', function (e) {
            if (e.relatedTarget && node.contains(e.relatedTarget)) return;
            node.__kbfFocus = false;
            if (!node.__kbfHover) startTimer(node);
        });
        node.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.stopPropagation(); removeToast(node); }
        });

        // Newest on top; the oldest beyond three goes
        host.insertBefore(node, host.firstChild);
        var all = host.querySelectorAll('.kbf-toast');
        var shown = [];
        for (var i = 0; i < all.length; i++) if (!all[i].__kbfGone) shown.push(all[i]);
        for (var j = MAX; j < shown.length; j++) removeToast(shown[j], true);

        if (node.classList.contains('kbf-toast-enter')) {
            // Next frame, so the slide-in runs
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () { node.classList.remove('kbf-toast-enter'); });
            });
        }
        announce(type, text);
        startTimer(node);
        return node;
    }

    // ---------- email sentence from the server's reply ----------
    // status: the emailStatus in the reply ('sent', 'waiting', 'failed',
    // 'deferred', 'pending', 'retry'). Anything else (switched off, no
    // address, not part of this action) gives '' so nothing is claimed.
    function emailText(status, who) {
        var subject = who || 'Their email';
        var s = String(status == null ? '' : status).toLowerCase();
        if (s === 'sent') return subject + ' has been sent.';
        if (s === 'waiting' || s === 'deferred' || s === 'pending' || s === 'retry' || s === 'queued') {
            return subject + ' will go out shortly.';
        }
        if (s === 'failed') return subject + ' could not be sent; you can resend it from the email log.';
        return '';
    }

    function emailType(status, type) {
        return String(status || '').toLowerCase() === 'failed' && (!type || type === 'success') ? 'warning' : (type || 'success');
    }

    // text + the email sentence for reply.emailStatus; a failed email turns
    // a success into a warning so it is not missed
    function fromReply(text, reply, type, who) {
        var status = reply && reply.emailStatus;
        var extra = emailText(status, who);
        return toast(text + (extra ? ' ' + extra : ''), emailType(status, type));
    }

    // ---------- toasts that survive a page change ----------
    function sessionStore() {
        if (KBF.storage && KBF.storage.session) return KBF.storage.session;
        return {
            get: function (k, fb) { try { var v = window.sessionStorage.getItem(k); return v === null ? fb : v; } catch (e) { return fb; } },
            set: function (k, v) { try { window.sessionStorage.setItem(k, String(v)); return true; } catch (e) { return false; } },
            remove: function (k) { try { window.sessionStorage.removeItem(k); return true; } catch (e) { return false; } }
        };
    }

    function next(message, type) {
        var text = String(message == null ? '' : message).trim();
        if (!text) return false;
        var store = sessionStore();
        var list = [];
        try { list = JSON.parse(store.get(NEXT_KEY, '[]')) || []; } catch (e) { list = []; }
        if (!Array.isArray(list)) list = [];
        list.push({ m: text, t: TYPES.indexOf(type) === -1 ? 'success' : type, at: Date.now() });
        return store.set(NEXT_KEY, JSON.stringify(list.slice(-MAX)));
    }

    // Admin pages stay hidden until the header is drawn: wait for that
    // (at most 6 seconds) so the message is seen and placed below it
    function showWaiting(tries) {
        tries = tries || 0;
        if (document.documentElement.style.visibility === 'hidden' && tries < 60) {
            window.setTimeout(function () { showWaiting(tries + 1); }, 100);
            return;
        }
        var store = sessionStore();
        var raw = store.get(NEXT_KEY, '');
        if (!raw) return;
        store.remove(NEXT_KEY);
        var list = [];
        try { list = JSON.parse(raw) || []; } catch (e) { list = []; }
        if (!Array.isArray(list)) return;
        list.forEach(function (item) {
            // Ignore anything left from long ago (for example a closed tab)
            if (item && item.m && (!item.at || Date.now() - item.at < 120000)) toast(item.m, item.t);
        });
    }

    toast.next = next;
    toast.email = emailText;
    toast.emailType = emailType;
    toast.fromReply = fromReply;
    toast.place = place;
    KBF.toast = toast;

    window.addEventListener('resize', function () { if (host) place(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { showWaiting(0); });
    else showWaiting(0);
})(window, document);
