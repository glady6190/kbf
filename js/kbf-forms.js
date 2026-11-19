/*
 * KBF shared form helpers.
 *
 * Load after escape.js (or on its own) on any page with a form or a save
 * button:
 *   <script src="/js/kbf-forms.js"></script>
 *
 * Busy buttons (no double submits):
 *   KBF.withBusy(button, 'Saving...', async function () { ... })
 *       Disables the button and shows the busy text while the function runs,
 *       then restores it. While busy, a second click or a second submit of
 *       the button's form does nothing. Return KBF.KEEP_BUSY from the
 *       function to leave the button disabled (for example before a redirect).
 *   var restore = KBF.setBusy(button, 'Saving...');  ...  restore();
 *       Lower level: call restore() when the request has finished.
 *   KBF.isBusy(button)
 *
 * One-time request ids (the server ignores a repeat of a request it has
 * already saved):
 *   KBF.requestId(holder)       id for this form (or any object), created
 *                               once and reused until cleared, so a retry
 *                               after a lost connection sends the same id
 *   KBF.clearRequestId(holder)  call after a successful save
 *   KBF.newRequestId()          a fresh id
 *   Send it as the header X-Request-Id (KBF.REQUEST_ID_HEADER).
 *
 * Field errors (shown next to the field and announced):
 *   KBF.showFieldError(fieldOrId, message)
 *   KBF.clearFieldError(fieldOrId)
 *   KBF.clearFormErrors(form)
 *
 * Labels: on load (and when content is added later) any <label> without a
 * for attribute is linked to the field that follows it, and a field with no
 * label gets an aria-label from its placeholder. Pages should still use
 * for/id pairs; this is a safety net.
 *
 * Names: KBF.NAME_PATTERN (for pattern="") and KBF.isValidName(text) accept
 * letters in any language, spaces, apostrophes, hyphens and full stops.
 */
(function (window) {
    'use strict';

    var doc = window.document;
    var KBF = window.KBF = window.KBF || {};
    var KEEP_BUSY = { keepBusy: true };
    var idCounter = 0;

    // ---------- Request ids ----------

    function newRequestId() {
        var c = window.crypto || window.msCrypto;
        try {
            if (c && typeof c.randomUUID === 'function') return c.randomUUID();
        } catch (e) { /* fall through */ }
        try {
            if (c && typeof c.getRandomValues === 'function') {
                var b = new Uint8Array(16);
                c.getRandomValues(b);
                b[6] = (b[6] & 0x0f) | 0x40;
                b[8] = (b[8] & 0x3f) | 0x80;
                var h = [];
                for (var i = 0; i < 16; i++) h.push((b[i] + 0x100).toString(16).slice(1));
                return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' +
                    h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' + h.slice(10).join('');
            }
        } catch (e2) { /* fall through */ }
        return 'r' + Date.now().toString(36) + '-' +
            Math.random().toString(36).slice(2, 12) + '-' + Math.random().toString(36).slice(2, 12);
    }

    var looseIds = {};

    function requestId(holder) {
        if (typeof holder === 'string' || holder === undefined || holder === null) {
            var key = String(holder || 'default');
            if (!looseIds[key]) looseIds[key] = newRequestId();
            return looseIds[key];
        }
        if (!holder._kbfRequestId) holder._kbfRequestId = newRequestId();
        return holder._kbfRequestId;
    }

    function clearRequestId(holder) {
        if (typeof holder === 'string' || holder === undefined || holder === null) {
            delete looseIds[String(holder || 'default')];
            return;
        }
        holder._kbfRequestId = null;
    }

    // ---------- Busy buttons ----------

    function formOf(btn) {
        if (!btn) return null;
        if (btn.form) return btn.form;
        return btn.closest ? btn.closest('form') : null;
    }

    function setBusy(btn, busyText) {
        if (!btn) return function () {};
        if (btn._kbfBusyRestore) return btn._kbfBusyRestore;
        var isInput = btn.tagName === 'INPUT';
        var oldHtml = isInput ? null : btn.innerHTML;
        var oldValue = isInput ? btn.value : null;
        var wasDisabled = !!btn.disabled;
        var form = formOf(btn);

        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        if (form) form.setAttribute('data-kbf-busy', '1');
        if (busyText) {
            if (isInput) btn.value = busyText;
            else btn.textContent = busyText;
        }

        var done = false;
        function restore() {
            if (done) return;
            done = true;
            btn._kbfBusyRestore = null;
            if (busyText) {
                if (isInput) btn.value = oldValue;
                else btn.innerHTML = oldHtml;
            }
            btn.disabled = wasDisabled;
            btn.removeAttribute('aria-busy');
            if (form) form.removeAttribute('data-kbf-busy');
        }
        btn._kbfBusyRestore = restore;
        return restore;
    }

    function isBusy(btn) {
        return !!(btn && btn._kbfBusyRestore);
    }

    // Run fn while the button is busy. A call while already busy does
    // nothing (resolves undefined).
    function withBusy(btn, busyText, fn) {
        if (isBusy(btn)) return Promise.resolve();
        var restore = setBusy(btn, busyText);
        var result;
        try {
            result = Promise.resolve(fn(restore));
        } catch (e) {
            restore();
            return Promise.reject(e);
        }
        return result.then(function (value) {
            if (value !== KEEP_BUSY) restore();
            return value;
        }, function (err) {
            restore();
            throw err;
        });
    }

    // A form whose submit button is busy cannot be submitted again (Enter in
    // a field included). Runs before the page's own submit handlers.
    if (doc && doc.addEventListener) {
        doc.addEventListener('submit', function (event) {
            var form = event.target;
            if (form && form.getAttribute && form.getAttribute('data-kbf-busy') === '1') {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    }

    // ---------- Field errors ----------

    function ensureId(el, prefix) {
        if (!el.id) {
            do {
                idCounter += 1;
                el.id = (prefix || 'kbf-field') + '-' + idCounter;
            } while (doc.getElementById(el.id) !== el);
        }
        return el.id;
    }

    function resolveField(field) {
        return typeof field === 'string' ? doc.getElementById(field) : field;
    }

    function addToken(el, attrName, token) {
        var list = (el.getAttribute(attrName) || '').split(/\s+/).filter(Boolean);
        if (list.indexOf(token) === -1) list.push(token);
        el.setAttribute(attrName, list.join(' '));
    }

    function removeToken(el, attrName, token) {
        var list = (el.getAttribute(attrName) || '').split(/\s+/).filter(function (t) {
            return t && t !== token;
        });
        if (list.length) el.setAttribute(attrName, list.join(' '));
        else el.removeAttribute(attrName);
    }

    function errorElementFor(field, create) {
        var id = ensureId(field) + '-error';
        var el = doc.getElementById(id);
        if (!el && create) {
            el = doc.createElement('p');
            el.id = id;
            el.className = 'kbf-field-error';
            el.setAttribute('role', 'alert');
            // Put the message after any wrapper the field sits in (phone
            // number widget, password show/hide wrapper).
            var anchor = field;
            var parent = field.parentElement;
            var iti = field.closest ? field.closest('.iti') : null;
            if (iti) {
                // intl-tel-input wraps the field (and its country search box)
                anchor = iti;
            } else if (parent && parent.classList.contains('relative') &&
                parent.querySelectorAll('input, select, textarea').length === 1) {
                anchor = parent;
            }
            anchor.insertAdjacentElement('afterend', el);
        }
        return el;
    }

    function showFieldError(field, message) {
        field = resolveField(field);
        if (!field) return;
        var el = errorElementFor(field, true);
        el.textContent = message || '';
        el.hidden = !message;
        field.setAttribute('aria-invalid', 'true');
        addToken(field, 'aria-describedby', el.id);
    }

    function clearFieldError(field) {
        field = resolveField(field);
        if (!field) return;
        field.removeAttribute('aria-invalid');
        var el = field.id ? doc.getElementById(field.id + '-error') : null;
        if (el) {
            el.textContent = '';
            el.hidden = true;
            removeToken(field, 'aria-describedby', el.id);
        }
    }

    function clearFormErrors(form) {
        if (!form) return;
        var fields = form.querySelectorAll('[aria-invalid="true"]');
        for (var i = 0; i < fields.length; i++) clearFieldError(fields[i]);
    }

    // Typing in a field marked invalid clears its message
    function onFieldEdited(event) {
        var t = event.target;
        if (t && t.getAttribute && t.getAttribute('aria-invalid') === 'true') {
            clearFieldError(t);
        }
    }
    if (doc && doc.addEventListener) {
        doc.addEventListener('input', onFieldEdited, true);
        doc.addEventListener('change', onFieldEdited, true);
    }

    // ---------- Labels (safety net) ----------

    var CONTROL_SELECTOR = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]), select, textarea';

    function isControl(el) {
        return !!(el && el.matches && el.matches(CONTROL_SELECTOR));
    }

    function hasName(control) {
        if (control.getAttribute('aria-label') || control.getAttribute('aria-labelledby')) return true;
        if (control.closest && control.closest('label')) return true;
        if (control.id) {
            var labels = doc.querySelectorAll('label[for]');
            for (var i = 0; i < labels.length; i++) {
                if (labels[i].htmlFor === control.id) return true;
            }
        }
        return false;
    }

    function controlAfterLabel(label) {
        var next = label.nextElementSibling;
        while (next) {
            if (next.tagName === 'LABEL') return null;
            if (isControl(next)) return next;
            var inner = next.querySelector ? next.querySelector(CONTROL_SELECTOR) : null;
            if (inner) return inner;
            next = next.nextElementSibling;
        }
        return null;
    }

    function linkLabels(root) {
        root = root || doc;
        if (!root.querySelectorAll) return;
        var labels = root.querySelectorAll('label:not([for])');
        for (var i = 0; i < labels.length; i++) {
            var label = labels[i];
            if (label.querySelector(CONTROL_SELECTOR)) continue;
            var control = controlAfterLabel(label);
            if (!control || hasName(control)) continue;
            label.htmlFor = ensureId(control);
        }
        var controls = root.querySelectorAll(CONTROL_SELECTOR);
        for (var j = 0; j < controls.length; j++) {
            var c = controls[j];
            if (hasName(c)) continue;
            var text = c.getAttribute('placeholder') || c.getAttribute('title') || '';
            if (text) c.setAttribute('aria-label', text);
        }
    }

    var linkTimer = null;
    function scheduleLinkLabels() {
        if (linkTimer) return;
        linkTimer = window.setTimeout(function () {
            linkTimer = null;
            linkLabels(doc);
        }, 150);
    }

    function startLabelLinking() {
        linkLabels(doc);
        if (window.MutationObserver && doc.body) {
            new window.MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
                        scheduleLinkLabels();
                        return;
                    }
                }
            }).observe(doc.body, { childList: true, subtree: true });
        }
    }

    if (doc) {
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', startLabelLinking);
        else startLabelLinking();
    }

    // ---------- Names ----------

    // Letters in any language (with accents), spaces, apostrophes (straight
    // and curly), hyphens and full stops. Same rule as kbfIsValidName() in
    // api/config.php.
    var NAME_REGEX = null;
    try {
        NAME_REGEX = new RegExp("^[\\p{L}\\p{M}][\\p{L}\\p{M}\\s'’.\\-]*$", 'u');
    } catch (e) {
        NAME_REGEX = /^[A-Za-zÀ-ɏḀ-ỿ][A-Za-zÀ-ɏḀ-ỿ\s'’.\-]*$/;
    }

    function isValidName(text) {
        return NAME_REGEX.test(String(text === null || text === undefined ? '' : text).trim());
    }

    KBF.KEEP_BUSY = KEEP_BUSY;
    KBF.REQUEST_ID_HEADER = 'X-Request-Id';
    KBF.newRequestId = newRequestId;
    KBF.requestId = requestId;
    KBF.clearRequestId = clearRequestId;
    KBF.setBusy = setBusy;
    KBF.isBusy = isBusy;
    KBF.withBusy = withBusy;
    KBF.showFieldError = showFieldError;
    KBF.clearFieldError = clearFieldError;
    KBF.clearFormErrors = clearFormErrors;
    KBF.linkLabels = linkLabels;
    KBF.isValidName = isValidName;
    // For pattern="" (HTML patterns use the v flag in current browsers)
    KBF.NAME_PATTERN = "[\\p{L}\\p{M}][\\p{L}\\p{M}\\s'’.\\-]*";
})(window);
