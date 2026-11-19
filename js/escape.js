/*
 * KBF shared HTML escaping helpers.
 *
 * Load this file before any script that builds HTML from data:
 *   <script src="/js/escape.js"></script>
 *
 * Exposes window.KBF.escapeHtml / escapeAttr / safeUrl / attr / linkHtml
 * and the globals escapeHtml, escapeAttr, esc, safeUrl so older call sites
 * keep working. Do not copy these helpers into pages; use this file.
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

    var KBF = window.KBF = window.KBF || {};
    KBF.escapeHtml = escapeHtml;
    KBF.escapeAttr = escapeAttr;
    KBF.safeUrl = safeUrl;
    KBF.attr = attr;
    KBF.linkHtml = linkHtml;

    window.escapeHtml = escapeHtml;
    window.escapeAttr = escapeAttr;
    window.esc = escapeHtml;
    window.safeUrl = safeUrl;
})(window);
