/* ============================================
 * KBF-UK DIALOG HELPER (staff pages)
 *
 * One way to make every dialog and slide-in panel keyboard friendly:
 *   - focus moves into the dialog when it opens
 *   - Tab and Shift+Tab stay inside it
 *   - Escape closes it
 *   - focus goes back to the button that opened it
 *   - everything behind it is inert (inert + aria-hidden)
 * The helper does not change how a dialog looks; the page still shows and
 * hides it with its own classes.
 *
 * Load after /js/escape.js:  <script src="/js/kbf-dialog.js"></script>
 *
 * 1. Markup dialogs shown and hidden with the 'hidden' class (or style
 *    display / the hidden attribute). Call once, after the page loads:
 *      KBFDialog.watch(modalEl, { onClose: closeMyModal });
 *    From then on the helper opens and closes with the element. onClose is
 *    what Escape calls (default: add the 'hidden' class).
 *
 * 2. Dialogs built in script and removed when closed:
 *      document.body.appendChild(wrap);
 *      KBFDialog.open(wrap, { onClose: close });   // close() calls wrap.remove()
 *    Removing the element is enough; the helper notices and tidies up.
 *    KBFDialog.close(wrap) does it straight away.
 *
 * A separate backdrop element beside a slide-in panel needs the attribute
 * data-kbf-backdrop so it is not made inert and still closes on click.
 *
 * Options: onClose (Escape), initialFocus (element or selector),
 *          role ('dialog' or 'alertdialog'), labelledBy (id), opener,
 *          escape (false = Escape does nothing, e.g. while saving),
 *          focusFirst (true = focus initialFocus straight away, before
 *          anything is made inert; the element must already be shown),
 *          inertOnly (true = make the rest inert without aria-hidden).
 *
 * 3. Ready-made dialogs in the staff style:
 *      KBFDialog.confirm({ title, message, confirmText, cancelText, danger, opener })
 *          .then(function (yes) { ... });
 *      KBFDialog.notice({ title, message })      // replaces window.alert
 *      KBFDialog.toast('Saved', 'success')        // short status message
 *    Every text is set with textContent.
 * ============================================ */
(function (window, document) {
    'use strict';

    var FOCUSABLE = 'a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
        'select:not([disabled]), textarea:not([disabled]), iframe, [contenteditable="true"], [tabindex]:not([tabindex="-1"])';

    var stack = [];          // open dialogs, top last
    var watched = [];        // { node, opts, observer }
    var idCounter = 0;

    function isShown(node) {
        if (!node || !node.isConnected) return false;
        if (node.hasAttribute('hidden')) return false;
        if (node.classList.contains('hidden')) return false;
        if (node.style && node.style.display === 'none') return false;
        return node.getClientRects().length > 0 || window.getComputedStyle(node).display !== 'none';
    }

    function focusables(node) {
        return Array.prototype.slice.call(node.querySelectorAll(FOCUSABLE)).filter(function (el) {
            if (el.closest('[inert]') && !node.closest('[inert]')) return false;
            return el.getClientRects().length > 0;
        });
    }

    function entryFor(node) {
        for (var i = 0; i < stack.length; i++) { if (stack[i].node === node) return i; }
        return -1;
    }

    // Make everything outside node inert. Returns what was changed.
    function inertOutside(node, inertOnly) {
        var changed = [];
        var el = node;
        while (el && el.parentElement && el !== document.body) {
            var parent = el.parentElement;
            Array.prototype.forEach.call(parent.children, function (sib) {
                if (sib === el) return;
                var tag = sib.tagName;
                if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'LINK' || tag === 'TEMPLATE') return;
                if (sib.hasAttribute('data-kbf-live')) return;   // shared status messages stay readable
                if (sib.hasAttribute('data-kbf-backdrop')) return; // a panel's backdrop must still take the closing click
                if (!sib.inert && !sib.hasAttribute('inert')) {
                    sib.setAttribute('inert', '');
                    if (inertOnly) {
                        changed.push({ el: sib, aria: false });
                    } else if (!sib.hasAttribute('aria-hidden')) {
                        sib.setAttribute('aria-hidden', 'true');
                        changed.push({ el: sib, aria: true });
                    } else {
                        changed.push({ el: sib, aria: false });
                    }
                }
            });
            el = parent;
        }
        return changed;
    }

    // A dialog opened on top of another may sit inside something the lower
    // dialog made inert: lift that for as long as this dialog is open.
    function liftInert(node) {
        var lifted = [];
        var el = node;
        while (el && el !== document.body) {
            if (el.hasAttribute('inert')) {
                lifted.push({ el: el, aria: el.getAttribute('aria-hidden') });
                el.removeAttribute('inert');
                el.removeAttribute('aria-hidden');
            }
            el = el.parentElement;
        }
        return lifted;
    }

    // ---------- dialogs taller than the screen ----------
    // A dialog card on a full-screen overlay is kept within the screen (the
    // viewport minus the overlay's padding, at least 2rem): its body
    // scrolls while the title row at the top and the button row at the
    // bottom stay in view. Side panels, full-screen views, the lightbox,
    // the cropper and anything marked data-kbf-no-fit are left alone.
    var FIT_SKIP = '.kbf-lb, .kbf-crop, [data-kbf-no-fit]';
    var FIELD = 'input:not([type="hidden"]), textarea, select, [contenteditable="true"]';

    function px(value) {
        var n = parseFloat(value);
        return isNaN(n) ? 0 : n;
    }

    function shownChildren(parent) {
        return Array.prototype.filter.call(parent.children, function (c) {
            var tag = c.tagName;
            if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEMPLATE') return false;
            return isShown(c) && c.getClientRects().length > 0;
        });
    }

    function opaqueBackground(from, stop) {
        var el = from;
        while (el && el !== stop.parentElement) {
            var bg = window.getComputedStyle(el).backgroundColor;
            if (bg && bg !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(bg)) return bg;
            el = el.parentElement;
        }
        return '#0f172a';
    }

    function fit(node) {
        if (!node || node.__kbfFit || node.tagName === 'ASIDE' || node.matches(FIT_SKIP)) return;
        var wrap, box;
        if (window.getComputedStyle(node).position === 'fixed') {
            wrap = node;
            var cards = shownChildren(node).filter(function (c) {
                var pos = window.getComputedStyle(c).position;
                return pos !== 'fixed' && pos !== 'absolute';
            });
            if (cards.length !== 1) return;
            box = cards[0];
        } else {
            wrap = node.parentElement;
            if (!wrap || window.getComputedStyle(wrap).position !== 'fixed') return;
            box = node;
        }
        if (box.matches(FIT_SKIP) || box.tagName === 'ASIDE') return;
        var vw = document.documentElement.clientWidth || window.innerWidth;
        var vh = window.innerHeight || document.documentElement.clientHeight;
        var wr = wrap.getBoundingClientRect();
        var br = box.getBoundingClientRect();
        if (wr.width < vw - 2 || wr.height < vh - 2) return;   // not a full-screen overlay
        if (br.width >= wr.width - 4) return;                  // a full-screen view, not a card
        node.__kbfFit = true;

        var ws = window.getComputedStyle(wrap);
        var bs = window.getComputedStyle(box);
        var gap = Math.max(32, Math.round(px(ws.paddingTop) + px(ws.paddingBottom) + px(bs.marginTop) + px(bs.marginBottom)));
        box.style.maxHeight = 'calc(100vh - ' + gap + 'px)';
        if (window.CSS && CSS.supports && CSS.supports('height', '100dvh')) box.style.maxHeight = 'calc(100dvh - ' + gap + 'px)';
        box.style.overflowY = 'auto';
        box.style.overscrollBehavior = 'contain';

        // The rows to keep in view: the first and last of the content (inside
        // a single form or wrapper when the card has one)
        var holder = box;
        var rows = shownChildren(holder);
        for (var depth = 0; depth < 3 && rows.length === 1 && rows[0].children.length > 1 &&
                (rows[0].tagName === 'FORM' || rows[0].tagName === 'DIV'); depth++) {
            holder = rows[0];
            rows = shownChildren(holder);
        }
        if (rows.length < 2) return;
        var bg = opaqueBackground(holder, box);
        var head = rows[0];
        var foot = rows[rows.length - 1];
        if (/^H[1-4]$/.test(head.tagName) || (head.querySelector('h1, h2, h3, h4') && !head.querySelector(FIELD))) {
            // The card's top padding moves into the row, so it sticks flush
            var pt = px(bs.paddingTop);
            box.style.paddingTop = '0px';
            head.style.paddingTop = (px(window.getComputedStyle(head).paddingTop) + pt) + 'px';
            head.style.position = 'sticky';
            head.style.top = '0px';
            head.style.zIndex = '2';
            head.style.backgroundColor = bg;
        }
        if (foot.querySelector('button') && !foot.querySelector(FIELD)) {
            var pb = px(bs.paddingBottom);
            box.style.paddingBottom = '0px';
            foot.style.paddingBottom = (px(window.getComputedStyle(foot).paddingBottom) + pb) + 'px';
            foot.style.position = 'sticky';
            foot.style.bottom = '0px';
            foot.style.zIndex = '2';
            foot.style.backgroundColor = bg;
        }
    }

    function resolveEl(value, node) {
        if (!value) return null;
        if (typeof value === 'string') return node.querySelector(value);
        return value;
    }

    function open(node, options) {
        if (!node) return null;
        var opts = options || {};
        var existing = entryFor(node);
        if (existing !== -1) {
            stack[existing].opts = opts;
            return stack[existing];
        }

        // Dialog semantics on the element that holds the content
        if (!node.hasAttribute('role')) node.setAttribute('role', opts.role || 'dialog');
        else if (opts.role) node.setAttribute('role', opts.role);
        node.setAttribute('aria-modal', 'true');
        if (opts.labelledBy) {
            node.setAttribute('aria-labelledby', opts.labelledBy);
        } else if (!node.hasAttribute('aria-labelledby') && !node.hasAttribute('aria-label')) {
            var heading = node.querySelector('h1, h2, h3, h4');
            if (heading) {
                if (!heading.id) heading.id = 'kbf-dialog-title-' + (++idCounter);
                node.setAttribute('aria-labelledby', heading.id);
            }
        }

        var active = document.activeElement;
        var entry = {
            node: node,
            opts: opts,
            opener: opts.opener || (active && active !== document.body && !node.contains(active) ? active : null),
            lifted: liftInert(node),
            inerted: null
        };
        // focusFirst: focus moves in before the rest becomes inert, so focus
        // is never left inside an inert element
        if (opts.focusFirst) {
            var first = resolveEl(opts.initialFocus, node);
            if (first) {
                if (!first.hasAttribute('tabindex') && !first.matches(FOCUSABLE)) first.setAttribute('tabindex', '-1');
                try { first.focus(); } catch (e) { /* ignore */ }
            }
        }
        entry.inerted = inertOutside(node, !!opts.inertOnly);
        stack.push(entry);

        // Move focus in (after the page's own show code has run)
        window.setTimeout(function () {
            if (entryFor(node) === -1) return;
            try { fit(node); } catch (e) { /* the dialog still works unfitted */ }
            if (node.contains(document.activeElement)) return;
            var target = resolveEl(opts.initialFocus, node) || node.querySelector('[autofocus]');
            if (!target) {
                var items = focusables(node);
                target = items[0] || null;
            }
            if (!target) {
                if (!node.hasAttribute('tabindex')) node.setAttribute('tabindex', '-1');
                target = node;
            }
            try { target.focus(); } catch (e) { /* ignore */ }
        }, 0);
        return entry;
    }

    function close(node, options) {
        var i = entryFor(node);
        if (i === -1) return;
        var entry = stack.splice(i, 1)[0];
        entry.inerted.forEach(function (c) {
            c.el.removeAttribute('inert');
            if (c.aria) c.el.removeAttribute('aria-hidden');
        });
        entry.lifted.forEach(function (c) {
            c.el.setAttribute('inert', '');
            if (c.aria !== null) c.el.setAttribute('aria-hidden', c.aria);
        });
        var keepFocus = options && options.returnFocus === false;
        if (!keepFocus && i === stack.length) {
            var back = entry.opener;
            if (back && back.isConnected && !back.closest('[inert]')) {
                try { back.focus(); } catch (e) { /* ignore */ }
            } else if (stack.length) {
                var below = focusables(stack[stack.length - 1].node);
                if (below[0]) below[0].focus();
            }
        }
    }

    function isOpen(node) { return entryFor(node) !== -1; }

    // ---------- keyboard ----------
    // Bubble phase: a field inside the dialog that uses Escape itself (for
    // example to close a suggestion list) calls preventDefault and wins.
    document.addEventListener('keydown', function (e) {
        var top = stack[stack.length - 1];
        if (!top) return;
        if (e.key === 'Escape' || e.key === 'Esc') {
            if (e.defaultPrevented || top.opts.escape === false) return;
            e.preventDefault();
            if (typeof top.opts.onClose === 'function') {
                top.opts.onClose();
            } else {
                top.node.classList.add('hidden');
            }
            // The page's close code may hide or remove the node: tidy up
            if (!isShown(top.node)) close(top.node);
            return;
        }
        if (e.key === 'Tab') {
            var items = focusables(top.node);
            if (!items.length) { e.preventDefault(); return; }
            var first = items[0];
            var last = items[items.length - 1];
            var inside = top.node.contains(document.activeElement);
            if (e.shiftKey && (document.activeElement === first || !inside)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (document.activeElement === last || !inside)) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    // Focus that lands behind the dialog (mouse click, script) comes back in
    document.addEventListener('focusin', function (e) {
        var top = stack[stack.length - 1];
        if (!top || top.node.contains(e.target)) return;
        if (e.target && e.target.closest && e.target.closest('[data-kbf-live]')) return;
        var items = focusables(top.node);
        if (items[0]) items[0].focus();
    });

    // ---------- removed or hidden dialogs ----------
    function sweep() {
        stack.slice().forEach(function (entry) {
            if (!entry.node.isConnected) close(entry.node);
        });
        watched.forEach(function (w) {
            var shown = isShown(w.node);
            var opened = isOpen(w.node);
            if (shown && !opened) open(w.node, w.opts);
            else if (!shown && opened) close(w.node);
        });
    }

    var bodyObserver = null;
    function observeBody() {
        if (bodyObserver || !document.body || !window.MutationObserver) return;
        bodyObserver = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].removedNodes.length) { sweep(); return; }
            }
        });
        bodyObserver.observe(document.body, { childList: true, subtree: true });
    }
    if (document.body) observeBody();
    else document.addEventListener('DOMContentLoaded', observeBody);

    // Follow a markup dialog: opens and closes with its visibility
    function watch(node, options) {
        if (!node) return;
        for (var i = 0; i < watched.length; i++) {
            if (watched[i].node === node) { watched[i].opts = options || {}; return; }
        }
        var w = { node: node, opts: options || {} };
        watched.push(w);
        if (window.MutationObserver) {
            w.observer = new MutationObserver(sweep);
            w.observer.observe(node, { attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
        }
        if (isShown(node)) open(node, w.opts);
    }

    // ---------- ready-made dialogs ----------
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    var BTN = 'px-4 py-2 font-black uppercase text-xs';
    var BTN_STYLE = 'min-height:44px;';

    function shell(titleText, role) {
        var wrap = el('div', 'fixed inset-0 flex items-center justify-center p-4 bg-black/70');
        wrap.style.zIndex = '80';
        wrap.setAttribute('data-kbf-dialog', '');
        var box = el('div', 'w-full max-w-md bg-slate-900 border-4 border-gray-700 p-5 sm:p-6 space-y-4');
        var h = el('h2', 'text-lg font-black uppercase text-white', titleText);
        h.id = 'kbf-dialog-title-' + (++idCounter);
        wrap.setAttribute('role', role || 'dialog');
        wrap.setAttribute('aria-labelledby', h.id);
        box.appendChild(h);
        wrap.appendChild(box);
        return { wrap: wrap, box: box };
    }

    function addMessage(m, message) {
        if (!message) return;
        var lines = Array.isArray(message) ? message : String(message).split('\n');
        var holder = el('div', 'space-y-2');
        holder.id = 'kbf-dialog-text-' + (++idCounter);
        lines.forEach(function (line) {
            if (String(line).trim() === '') return;
            holder.appendChild(el('p', 'text-sm text-gray-300 font-semibold', line));
        });
        m.wrap.setAttribute('aria-describedby', holder.id);
        m.box.appendChild(holder);
    }

    // Resolves true (confirmed) or false
    function confirmDialog(options) {
        var o = options || {};
        return new Promise(function (resolve) {
            var m = shell(o.title || 'Are you sure?', 'alertdialog');
            addMessage(m, o.message);
            var row = el('div', 'flex flex-col-reverse sm:flex-row sm:justify-end gap-2');
            var no = el('button', BTN + ' bg-slate-800 border-2 border-gray-700 text-gray-200 hover:border-gray-500', o.cancelText || 'Cancel');
            var yes = el('button', BTN + (o.danger ? ' bg-red-600 text-white border-2 border-red-600 hover:bg-red-700' : ' border-2'), o.confirmText || 'Confirm');
            no.type = 'button';
            yes.type = 'button';
            no.setAttribute('style', BTN_STYLE);
            yes.setAttribute('style', BTN_STYLE + (o.danger ? '' : 'background:var(--theme-primary);border-color:var(--theme-primary);color:#0F172A;'));
            row.appendChild(no);
            row.appendChild(yes);
            m.box.appendChild(row);
            var done = false;
            function finish(answer) {
                if (done) return;
                done = true;
                close(m.wrap);
                if (m.wrap.parentNode) m.wrap.parentNode.removeChild(m.wrap);
                resolve(answer);
            }
            no.addEventListener('click', function () { finish(false); });
            yes.addEventListener('click', function () { finish(true); });
            m.wrap.addEventListener('click', function (e) { if (e.target === m.wrap) finish(false); });
            document.body.appendChild(m.wrap);
            open(m.wrap, { onClose: function () { finish(false); }, initialFocus: o.danger ? no : yes, opener: o.opener || null });
        });
    }

    // Resolves when closed
    function notice(options) {
        var o = typeof options === 'string' ? { message: options } : (options || {});
        return new Promise(function (resolve) {
            var m = shell(o.title || 'Please note', 'alertdialog');
            addMessage(m, o.message);
            var row = el('div', 'flex justify-end');
            var ok = el('button', BTN + ' border-2', o.buttonText || 'OK');
            ok.type = 'button';
            ok.setAttribute('style', BTN_STYLE + 'background:var(--theme-primary);border-color:var(--theme-primary);color:#0F172A;');
            row.appendChild(ok);
            m.box.appendChild(row);
            function finish() {
                close(m.wrap);
                if (m.wrap.parentNode) m.wrap.parentNode.removeChild(m.wrap);
                resolve();
            }
            ok.addEventListener('click', finish);
            document.body.appendChild(m.wrap);
            open(m.wrap, { onClose: finish, initialFocus: ok, opener: o.opener || null });
        });
    }

    // Short status message, read out by screen readers. kind: 'success'
    // (default), 'info', 'warning' or 'error'. Uses the shared toasts in
    // js/kbf-toast.js (KBF.toast) when the page loads them.
    var toastTimer = null;
    function toast(message, kind) {
        if (window.KBF && typeof window.KBF.toast === 'function') {
            return window.KBF.toast(message, kind);
        }
        var host = document.getElementById('kbf-toast');
        if (!host) {
            host = el('div', 'fixed bottom-4 left-4 right-4');
            host.style.zIndex = '90';
            host.style.maxWidth = '24rem';
            host.style.marginLeft = 'auto';
            host.id = 'kbf-toast';
            host.setAttribute('data-kbf-live', '');
            host.setAttribute('role', 'status');
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }
        var colours = kind === 'error'
            ? 'border-red-500 bg-slate-900 text-red-400'
            : (kind === 'info' ? 'border-gray-500 bg-slate-900 text-gray-200' : 'border-green-500 bg-slate-900 text-green-400');
        host.innerHTML = '';
        var box = el('div', 'p-4 border-4 font-bold text-sm shadow-2xl ' + colours, message);
        host.appendChild(box);
        if (toastTimer) window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () { host.innerHTML = ''; }, kind === 'error' ? 8000 : 4000);
    }

    window.KBFDialog = {
        open: open,
        close: close,
        isOpen: isOpen,
        watch: watch,
        confirm: confirmDialog,
        notice: notice,
        toast: toast,
        focusables: focusables
    };
})(window, document);
