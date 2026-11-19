/*
 * KBF shared HTML escaping helpers.
 *
 * Load this file before any script that builds HTML from data:
 *   <script src="/js/escape.js"></script>
 *
 * Exposes window.KBF.escapeHtml / escapeAttr / safeUrl / attr / linkHtml
 * and the globals escapeHtml, escapeAttr, esc, safeUrl so older call sites
 * keep working. Do not copy these helpers into pages; use this file.
 *
 * Image helpers (also globals): imageVariant, imgVariantAttrs,
 * setImageVariant. Images using a resized copy carry data-fallback with the
 * original url; a document 'error' listener swaps to it once.
 */
(function (window) {
    'use strict';

    var HTML_ESCAPES = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };

    // Escape a value for use as HTML text or inside a quoted attribute.
    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value).replace(/[&<>"']/g, function (ch) {
            return HTML_ESCAPES[ch];
        });
    }

    // Same as escapeHtml; named for readability at attribute call sites.
    var escapeAttr = escapeHtml;

    // Remove whitespace, control and zero-width characters.
    function stripInvisible(text) {
        var out = '';
        for (var i = 0; i < text.length; i++) {
            var c = text.charCodeAt(i);
            if (c <= 0x20 || (c >= 0x7F && c <= 0xA0) || c === 0xAD ||
                (c >= 0x2000 && c <= 0x200F) || c === 0x2028 || c === 0x2029 ||
                c === 0x3000 || c === 0xFEFF) {
                continue;
            }
            out += text.charAt(i);
        }
        return out;
    }

    // Return url when it is safe to put in href/src, otherwise the fallback.
    // Allowed: relative paths, #fragments, ?query, http:, https:
    // (and mailto:/tel: when opts.allowMailto / opts.allowTel is true).
    // opts.fallback sets the value returned for a rejected url (default '').
    function safeUrl(url, opts) {
        var options = opts || {};
        var fallback = options.fallback !== undefined ? options.fallback : '';
        if (url === null || url === undefined) return fallback;
        var raw = String(url).trim();
        if (raw === '') return fallback;

        // Remove whitespace and control characters before reading the scheme,
        // so values such as "java\nscript:" or " javascript:" are caught.
        var compact = stripInvisible(raw);
        if (compact === '') return fallback;

        // Protocol-relative URLs ("//host/...") would load from another site.
        if (/^[\/\\]{2}/.test(compact)) return fallback;

        var schemeMatch = /^([a-zA-Z][a-zA-Z0-9+.\-]*):/.exec(compact);
        if (!schemeMatch) {
            // No scheme: relative path, fragment or query. A colon before the
            // first '/', '?' or '#' would be read as a scheme by browsers.
            var firstPart = compact.split(/[\/?#]/)[0];
            if (firstPart.indexOf(':') !== -1) return fallback;
            return raw;
        }

        var scheme = schemeMatch[1].toLowerCase();
        if (scheme === 'http' || scheme === 'https') return raw;
        if (scheme === 'mailto' && options.allowMailto) return raw;
        if (scheme === 'tel' && options.allowTel) return raw;
        return fallback;
    }

    // Build ` name="value"` with the value escaped. Returns '' for null/undefined.
    function attr(name, value) {
        if (value === null || value === undefined) return '';
        return ' ' + name + '="' + escapeHtml(value) + '"';
    }

    // Build an <a> tag. href goes through safeUrl; text is escaped.
    // attrs is an optional object of extra attributes (values are escaped).
    // opts is passed to safeUrl. A rejected href renders the text as a <span>.
    function linkHtml(href, text, attrs, opts) {
        var url = safeUrl(href, opts);
        var extra = '';
        if (attrs) {
            for (var key in attrs) {
                if (Object.prototype.hasOwnProperty.call(attrs, key) && /^[a-zA-Z_:][a-zA-Z0-9_:.\-]*$/.test(key)) {
                    extra += attr(key, attrs[key]);
                }
            }
        }
        if (!url) return '<span' + extra + '>' + escapeHtml(text) + '</span>';
        return '<a href="' + escapeHtml(url) + '"' + extra + '>' + escapeHtml(text) + '</a>';
    }

    // Uploaded images have two resized copies next to the original:
    //   /uploads/<folder>/<name>.d.jpg  display copy (max 1600 px)
    //   /uploads/<folder>/<name>.t.jpg  thumbnail (max 480 px)
    // Only these folders have copies; other paths are returned unchanged.
    var VARIANT_PATTERN = /^((?:https?:\/\/[^\/?#]+)?\/?uploads\/(?:gallery\/\d+|announcements|profiles|registrations|tournaments)\/[^\/?#]+)\.(jpe?g|png|webp|gif)([?#].*)?$/i;

    // Return the 'thumb' or 'display' copy of an uploaded image url.
    // Returns url unchanged when it has no copies or is already a copy.
    function imageVariant(url, kind) {
        if (typeof url !== 'string' || url === '') return url;
        var suffix = kind === 'display' ? '.d.jpg' : (kind === 'thumb' ? '.t.jpg' : '');
        if (!suffix) return url;
        var match = VARIANT_PATTERN.exec(url.trim());
        if (!match) return url;
        // Absolute urls only count when they point at this site.
        if (/^https?:/i.test(match[1])) {
            var origin = window.location && window.location.origin;
            if (!origin || match[1].toLowerCase().indexOf(origin.toLowerCase() + '/') !== 0) return url;
        }
        if (/\.[dt]$/i.test(match[1])) return url;
        return match[1] + suffix + (match[3] || '');
    }

    // Swap a failed variant image back to its original (once).
    function handleVariantError(event) {
        var img = event.target;
        if (!img || img.tagName !== 'IMG' || !img.hasAttribute('data-fallback')) return;
        var fallback = img.getAttribute('data-fallback');
        img.removeAttribute('data-fallback');
        if (!fallback || img.getAttribute('src') === fallback) return;
        // Stop page onerror handlers (hide image, show initials) from
        // reacting to the missing copy; they still run if the original fails.
        event.stopImmediatePropagation();
        img.src = fallback;
    }

    if (window.document && window.document.addEventListener) {
        window.document.addEventListener('error', handleVariantError, true);
    }

    // Build ` src="..." data-fallback="..."` for an <img> in an HTML string.
    // The url goes through safeUrl; values are escaped.
    function imgVariantAttrs(url, kind) {
        var original = safeUrl(url);
        var variant = imageVariant(original, kind);
        if (variant && variant !== original) {
            return ' src="' + escapeHtml(variant) + '" data-fallback="' + escapeHtml(original) + '"';
        }
        return ' src="' + escapeHtml(original) + '"';
    }

    // Point an <img> element at the variant of url, falling back to url.
    function setImageVariant(img, url, kind) {
        if (!img) return img;
        var original = url === null || url === undefined ? '' : String(url);
        var variant = imageVariant(original, kind);
        if (variant && variant !== original) {
            img.setAttribute('data-fallback', original);
            if (!img._kbfVariantListener) {
                // Covers images that fail before they are added to the page.
                img.addEventListener('error', handleVariantError);
                img._kbfVariantListener = true;
            }
        } else {
            img.removeAttribute('data-fallback');
        }
        img.src = variant;
        return img;
    }

    var KBF = window.KBF = window.KBF || {};
    KBF.escapeHtml = escapeHtml;
    KBF.escapeAttr = escapeAttr;
    KBF.safeUrl = safeUrl;
    KBF.attr = attr;
    KBF.linkHtml = linkHtml;
    KBF.imageVariant = imageVariant;
    KBF.imgVariantAttrs = imgVariantAttrs;
    KBF.setImageVariant = setImageVariant;

    window.escapeHtml = escapeHtml;
    window.escapeAttr = escapeAttr;
    window.esc = escapeHtml;
    window.safeUrl = safeUrl;
    window.imageVariant = imageVariant;
    window.imgVariantAttrs = imgVariantAttrs;
    window.setImageVariant = setImageVariant;
})(window);
