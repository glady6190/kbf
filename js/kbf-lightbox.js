/* ============================================
 * KBF-UK PHOTO VIEWER
 *
 * Clicking or tapping a member photo shows it large in a dialog: the
 * display copy of the image (falling back to the original), the member's
 * name as the caption, a Close button, Escape to close, and focus moved in
 * and given back to the photo afterwards (js/kbf-dialog.js when loaded).
 *
 * Load after /js/escape.js (and /js/kbf-dialog.js):
 *   <script src="/js/kbf-lightbox.js"></script>
 *
 *   KBFLightbox.attach(el, photoUrl, name, { fill: true })
 *       Makes el (an <img>, or a box holding one) a real button that opens
 *       the viewer. el is wrapped in a new <button> unless it is a button
 *       already. Returns the button, or el unchanged when there is no
 *       photo (initials shown, under 18 or none): nothing to open then.
 *       fill: the button fills its parent; round: a round focus ring.
 *   KBFLightbox.detach(el)  undoes attach (photo replaced by initials)
 *   KBFLightbox.sync(el, photoUrl, name, options)  attach or detach
 *   KBFLightbox.open({ src, name, opener })
 *
 * Every text is set with textContent; the url goes through KBF.safeUrl.
 * The look comes from a small style block added here (no Tailwind classes).
 * ============================================ */
(function (window, document) {
    'use strict';

    var CSS =
        '.kbf-photo-btn{display:block;padding:0;margin:0;border:0;background:none;color:inherit;font:inherit;cursor:zoom-in;border-radius:inherit;line-height:0}' +
        '.kbf-photo-btn.kbf-photo-btn-fill{width:100%;height:100%}' +
        '.kbf-photo-btn.kbf-photo-btn-round{border-radius:9999px}' +
        '.kbf-photo-btn:hover img{filter:brightness(1.08)}' +
        '.kbf-photo-btn:focus-visible{outline:3px solid var(--theme-primary,#fff);outline-offset:3px}' +
        '.kbf-lb{position:fixed;inset:0;z-index:95;display:flex;align-items:center;justify-content:center;padding:4rem 1rem 1rem;background:rgba(0,0,0,.88)}' +
        '.kbf-lb-box{display:flex;flex-direction:column;align-items:center;gap:.75rem;max-width:min(94vw,900px);max-height:100%;min-width:0}' +
        '.kbf-lb-img{display:block;max-width:100%;max-height:calc(100vh - 9rem);width:auto;height:auto;object-fit:contain;background:#0f172a;border:4px solid #374151}' +
        '.kbf-lb-cap{margin:0;color:#fff;font-weight:900;text-transform:uppercase;font-size:1rem;line-height:1.3;text-align:center;overflow-wrap:anywhere}' +
        '.kbf-lb-note{margin:0;color:#d1d5db;font-weight:700;font-size:.875rem;text-align:center}' +
        '.kbf-lb-close{position:absolute;top:.75rem;right:.75rem;min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem .9rem;background:#1e293b;border:2px solid #4b5563;color:#fff;font-weight:900;font-size:.75rem;text-transform:uppercase;cursor:pointer}' +
        '.kbf-lb-close:hover{border-color:var(--theme-primary,#fff)}' +
        '.kbf-lb-close:focus-visible{outline:3px solid var(--theme-primary,#fff);outline-offset:2px}';

    function addStyle() {
        if (document.getElementById('kbf-lightbox-style')) return;
        var style = document.createElement('style');
        style.id = 'kbf-lightbox-style';
        style.textContent = CSS;
        (document.head || document.documentElement).appendChild(style);
    }

    function cleanUrl(url) {
        if (typeof url !== 'string' || url === '') return '';
        var KBF = window.KBF;
        return KBF && KBF.safeUrl ? KBF.safeUrl(url) : (/^\/[^\/\\]/.test(url) ? url : '');
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    var current = null;

    function open(options) {
        var o = options || {};
        var src = cleanUrl(o.src);
        if (!src) return;
        if (current) current.close();
        addStyle();
        var name = String(o.name || '').trim();

        var wrap = el('div', 'kbf-lb');
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        var box = el('figure', 'kbf-lb-box');
        box.style.margin = '0';
        var img = el('img', 'kbf-lb-img');
        img.alt = name ? 'Photo of ' + name : 'Member photo';
        img.decoding = 'async';
        var cap = el('h2', 'kbf-lb-cap', name || 'Member photo');
        cap.id = 'kbf-lb-title-' + Date.now();
        wrap.setAttribute('aria-labelledby', cap.id);
        var figcap = el('figcaption');
        figcap.appendChild(cap);
        var closeBtn = el('button', 'kbf-lb-close', 'Close');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Close photo');

        box.appendChild(img);
        box.appendChild(figcap);
        wrap.appendChild(closeBtn);
        wrap.appendChild(box);

        var opener = o.opener || (document.activeElement !== document.body ? document.activeElement : null);
        var oldOverflow = document.documentElement.style.overflow;
        var closed = false;
        var ownKeys = null;

        function close() {
            if (closed) return;
            closed = true;
            current = null;
            document.documentElement.style.overflow = oldOverflow;
            if (ownKeys) document.removeEventListener('keydown', ownKeys);
            if (window.KBFDialog) window.KBFDialog.close(wrap);
            if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
            if (!window.KBFDialog && opener && opener.focus && opener.isConnected) {
                try { opener.focus(); } catch (e) { /* ignore */ }
            }
        }

        closeBtn.addEventListener('click', close);
        wrap.addEventListener('click', function (e) {
            if (e.target === wrap || e.target === box) close();
        });

        // The display copy first, then the JPEG copy, then the original
        img.addEventListener('load', function () { img.style.visibility = ''; });
        img.style.visibility = 'hidden';
        if (window.KBF && window.KBF.setImageVariant) window.KBF.setImageVariant(img, src, 'display');
        else img.src = src;
        // Registered after setImageVariant so it only hears the last failure
        img.addEventListener('error', function () {
            img.style.display = 'none';
            if (!box.querySelector('.kbf-lb-note')) box.insertBefore(el('p', 'kbf-lb-note', 'This photo could not be loaded.'), figcap);
        });

        document.body.appendChild(wrap);
        document.documentElement.style.overflow = 'hidden';
        if (window.KBFDialog) {
            window.KBFDialog.open(wrap, { onClose: close, initialFocus: closeBtn, opener: opener, labelledBy: cap.id });
        } else {
            // Without the shared helper: Escape closes, Tab stays on Close
            ownKeys = function (e) {
                if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); close(); }
                else if (e.key === 'Tab') { e.preventDefault(); closeBtn.focus(); }
            };
            document.addEventListener('keydown', ownKeys);
            window.setTimeout(function () { closeBtn.focus(); }, 0);
        }
        current = { close: close };
    }

    function attach(target, url, name, options) {
        if (!target) return target;
        var src = cleanUrl(url);
        if (!src) return target;
        addStyle();
        var btn = target;
        var parent = target.parentNode;
        if (parent && parent.classList && parent.classList.contains('kbf-photo-btn')) {
            btn = parent;                       // attached before: update it
            btn.classList.remove('hidden');
        } else if (target.tagName !== 'BUTTON') {
            btn = document.createElement('button');
            btn.type = 'button';
            if (target.parentNode) target.parentNode.replaceChild(btn, target);
            btn.appendChild(target);
        }
        btn.classList.add('kbf-photo-btn');
        if (options && options.fill) btn.classList.add('kbf-photo-btn-fill');
        if (options && options.round) btn.classList.add('kbf-photo-btn-round');
        var label = String(name || '').trim();
        btn.setAttribute('aria-label', 'View photo of ' + (label || 'this member') + ' larger');
        btn.title = 'View larger';
        btn._kbfLightbox = { src: src, name: label };
        if (!btn._kbfLightboxBound) {
            btn._kbfLightboxBound = true;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var d = btn._kbfLightbox;
                if (d) open({ src: d.src, name: d.name, opener: btn });
            });
        }
        return btn;
    }

    // Undo attach (the photo was replaced by initials): the button made by
    // attach is swapped back for the element it held.
    function detach(target) {
        if (!target) return target;
        var btn = target.parentNode;
        if (!btn || !btn.classList || !btn.classList.contains('kbf-photo-btn') || !btn.parentNode) return target;
        btn.parentNode.replaceChild(target, btn);
        return target;
    }

    // Attach when there is a photo, detach when there is none
    function sync(target, url, name, options) {
        return cleanUrl(url) ? attach(target, url, name, options) : detach(target);
    }

    window.KBFLightbox = { open: open, attach: attach, detach: detach, sync: sync };
})(window, document);
