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
 * Also KBF.storage (localStorage / sessionStorage that never throws) and
 * KBF.showLoadError (load-failed message with a "Try again" button).
 *
 * Image helpers (also globals): imageVariant, imgVariantAttrs,
 * setImageVariant. Images using a resized copy carry data-fallback with the
 * original url; a document 'error' listener swaps to it once.
 *
 * Dates: KBF.formatDate(value, opts) -> "27 Sep 2026" (fixed English month
 * names; see the notes on formatDate), KBF.formatTime, KBF.parseDate and the
 * arrays KBF.MONTHS_SHORT / MONTHS_LONG / WEEKDAYS_SHORT / WEEKDAYS_LONG.
 * Text: KBF.titleCase (also global titleCase) for venues, towns and
 * organiser names on the public site.
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

    // Browser storage that never throws. Some privacy settings, embedded
    // browsers and private modes block site data, and then even reading
    // window.localStorage throws. Use these instead of localStorage and
    // sessionStorage directly:
    //   KBF.storage.get(key, fallback)   KBF.storage.set(key, value)
    //   KBF.storage.remove(key)          KBF.storage.session.get(...) etc.
    // set() and remove() return true when the browser accepted the change.
    function makeStorage(name) {
        function store() {
            try { return window[name] || null; } catch (e) { return null; }
        }
        return {
            get: function (key, fallback) {
                var fb = fallback === undefined ? null : fallback;
                try {
                    var s = store();
                    if (!s) return fb;
                    var value = s.getItem(key);
                    return value === null ? fb : value;
                } catch (e) { return fb; }
            },
            set: function (key, value) {
                try {
                    var s = store();
                    if (!s) return false;
                    s.setItem(key, String(value));
                    return true;
                } catch (e) { return false; }
            },
            remove: function (key) {
                try {
                    var s = store();
                    if (!s) return false;
                    s.removeItem(key);
                    return true;
                } catch (e) { return false; }
            },
            clear: function () {
                try {
                    var s = store();
                    if (!s) return false;
                    s.clear();
                    return true;
                } catch (e) { return false; }
            }
        };
    }
    var storage = makeStorage('localStorage');
    storage.session = makeStorage('sessionStorage');

    // Replace the contents of target (an element or its id) with a short
    // "could not be loaded" message and a "Try again" button that calls
    // retry. Text is set with textContent. Returns the message element.
    // Pressing "Try again" first puts back what target held before the
    // first error (such as a loading spinner), then calls retry.
    //   KBF.showLoadError('news-grid', { message: '...', retry: loadNews });
    function showLoadError(target, opts) {
        var options = opts || {};
        var el = typeof target === 'string' ? document.getElementById(target) : target;
        if (!el) return null;
        if (el._kbfBeforeError === undefined && !options.append) {
            // Page markup written by the site itself, not data
            el._kbfBeforeError = el.innerHTML;
        }
        var box = document.createElement(options.tag || 'div');
        box.className = 'kbf-load-error' + (options.className ? ' ' + options.className : '');
        box.setAttribute('role', 'alert');
        var text = document.createElement('p');
        text.className = 'kbf-load-error-text';
        text.textContent = options.message || 'This could not be loaded. Please check your connection and try again.';
        box.appendChild(text);
        if (typeof options.retry === 'function') {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'kbf-load-error-btn';
            btn.textContent = options.retryLabel || 'Try again';
            btn.addEventListener('click', function () {
                btn.disabled = true;
                btn.textContent = 'Loading...';
                if (!options.append && typeof el._kbfBeforeError === 'string' && el.contains(box)) {
                    el.innerHTML = el._kbfBeforeError;
                }
                options.retry();
            });
            box.appendChild(btn);
        }
        if (options.append) {
            el.appendChild(box);
        } else {
            el.innerHTML = '';
            el.appendChild(box);
        }
        return box;
    }

    // ---- Dates -----------------------------------------------------------
    // One date format for the whole site, with fixed English month names
    // (browser locale output can give "Sept"). Never use toLocaleDateString
    // or Intl month names for display; use these instead.
    var MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];
    var WEEKDAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var WEEKDAYS_LONG = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    // 'YYYY-MM-DD', 'YYYY-MM-DD HH:MM[:SS]' or with a 'T': no zone given
    var WALL_CLOCK = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?)?$/;

    // Zone to show a value in: wall-clock strings are already local times
    // (stored as UK times), so opts.timeZone only applies to instants.
    function zoneFor(value, timeZone) {
        if (!timeZone) return '';
        if (typeof value === 'string' && WALL_CLOCK.test(value.trim())) return '';
        return timeZone;
    }

    // Read a date value as a Date. 'YYYY-MM-DD' and 'YYYY-MM-DD HH:MM[:SS]'
    // (also with a 'T') are read as local wall-clock times, not shifted from
    // UTC. Values with a Z or +hh:mm offset, numbers and Date objects are
    // read as instants. Returns null when the value is not a date.
    function parseDate(value) {
        if (value === null || value === undefined || value === '') return null;
        if (value instanceof Date) return isNaN(value.getTime()) ? null : value;
        if (typeof value === 'number') {
            var fromNumber = new Date(value);
            return isNaN(fromNumber.getTime()) ? null : fromNumber;
        }
        var text = String(value).trim();
        var m = WALL_CLOCK.exec(text);
        if (m) {
            var local = new Date(+m[1], +m[2] - 1, +m[3], m[4] ? +m[4] : 0, m[5] ? +m[5] : 0, m[6] ? +m[6] : 0);
            // Reject impossible dates such as 2026-02-31
            if (isNaN(local.getTime()) || local.getMonth() !== +m[2] - 1) return null;
            return local;
        }
        var parsed = new Date(text);
        return isNaN(parsed.getTime()) ? null : parsed;
    }

    // Wall-clock parts of a Date, in the browser's zone or in opts.timeZone
    // (such as 'Europe/London' or 'UTC'; numeric parts only).
    function dateParts(date, timeZone) {
        if (timeZone && window.Intl && Intl.DateTimeFormat) {
            try {
                var parts = {};
                new Intl.DateTimeFormat('en-GB', {
                    timeZone: timeZone, year: 'numeric', month: 'numeric', day: 'numeric',
                    hour: 'numeric', minute: 'numeric', hourCycle: 'h23'
                }).formatToParts(date).forEach(function (p) { parts[p.type] = p.value; });
                var y = +parts.year, mo = +parts.month - 1, d = +parts.day;
                return {
                    year: y, month: mo, day: d,
                    weekday: new Date(Date.UTC(y, mo, d)).getUTCDay(),
                    hour: (+parts.hour) % 24, minute: +parts.minute
                };
            } catch (e) { /* unknown zone: use the browser's */ }
        }
        return {
            year: date.getFullYear(), month: date.getMonth(), day: date.getDate(),
            weekday: date.getDay(), hour: date.getHours(), minute: date.getMinutes()
        };
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    // Format a date for display: "27 Sep 2026".
    // opts.weekday: true or 'short' -> "Sat 27 Sep 2026", 'long' -> "Saturday 27 Sep 2026"
    // opts.month:   'long' -> "27 September 2026" (default 'short')
    // opts.year:    false leaves the year out -> "27 Sep"
    // opts.time:    true adds the time -> "27 Sep 2026, 14:05"
    // opts.timeZone: show an instant (Date, number, or text with Z/+hh:mm) in
    //               that zone, e.g. 'Europe/London'. Plain 'YYYY-MM-DD[ HH:MM]'
    //               text is shown exactly as written.
    // opts.fallback: returned when value is not a date (default '')
    function formatDate(value, opts) {
        var o = opts || {};
        var date = parseDate(value);
        if (!date) return o.fallback !== undefined ? o.fallback : '';
        var p = dateParts(date, zoneFor(value, o.timeZone));
        var out = p.day + ' ' + (o.month === 'long' ? MONTHS_LONG : MONTHS_SHORT)[p.month];
        if (o.year !== false) out += ' ' + p.year;
        if (o.weekday) out = (o.weekday === 'long' ? WEEKDAYS_LONG : WEEKDAYS_SHORT)[p.weekday] + ' ' + out;
        if (o.time) out += ', ' + pad2(p.hour) + ':' + pad2(p.minute);
        return out;
    }

    // "14:05" (24-hour). opts.timeZone as for formatDate.
    function formatTime(value, opts) {
        var o = opts || {};
        var date = parseDate(value);
        if (!date) return o.fallback !== undefined ? o.fallback : '';
        var p = dateParts(date, zoneFor(value, o.timeZone));
        return pad2(p.hour) + ':' + pad2(p.minute);
    }

    // ---- Title case for venues, towns and organiser names -----------------
    // Display only: stored text stays as typed. Escape the result afterwards
    // (escapeHtml) or set it with textContent.
    //   "LEEDS sports centre, ls1 4ab" -> "LEEDS Sports Centre, LS1 4AB"
    //   "hall of the KBF in stoke-on-trent" -> "Hall of the KBF in Stoke-on-Trent"
    // Words of two or more capitals (KBF, UK, MARC) are kept as typed, UK
    // postcodes are put in capitals, small joining words are lower case
    // except at the start, and Mc/O'/D' names and hyphenated parts are handled.
    var SMALL_WORDS = {
        a: 1, an: 1, and: 1, as: 1, at: 1, by: 1, de: 1, del: 1, der: 1, des: 1, du: 1,
        en: 1, 'for': 1, from: 1, 'in': 1, into: 1, la: 1, le: 1, les: 1, nor: 1, of: 1,
        on: 1, or: 1, the: 1, to: 1, upon: 1, van: 1, von: 1, via: 1, with: 1
    };
    var POSTCODE = /\b([A-Za-z]{1,2}\d[A-Za-z\d]?)\s*(\d[A-Za-z]{2})\b/g;

    function capitalise(word) {
        return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
    }

    function titleCasePart(part, isFirst) {
        if (part === '') return part;
        var letters = part.replace(/[^A-Za-zÀ-ɏ]/g, '');
        if (letters === '') return part;
        // Contains a digit (postcodes, unit numbers, "3rd"): leave as typed
        if (/\d/.test(part)) return part;
        // Acronyms: two or more capitals and no lower case letters
        if (letters.length >= 2 && letters === letters.toUpperCase() && letters !== letters.toLowerCase()) return part;
        var lower = part.toLowerCase();
        var core = lower.replace(/[^a-zÀ-ɏ'’.]/g, '');
        if (!isFirst && SMALL_WORDS[core]) return lower;
        // Already typed with an inner capital: MacLeod, DeVere, McDonald
        if (/^[A-Z][a-zÀ-ɏ]+[A-Z][a-zÀ-ɏ]+$/.test(part)) return part;
        // Mc + capital: mcdonald -> McDonald
        var mc = /^([^A-Za-z]*)mc([a-zÀ-ɏ])(.+)$/.exec(lower);
        if (mc) return mc[1] + 'Mc' + mc[2].toUpperCase() + mc[3];
        // O'Brien, D'Souza (one letter before the apostrophe)
        var apos = /^([^A-Za-z]*)([a-z])(['’])([a-zÀ-ɏ])(.*)$/.exec(lower);
        if (apos) return apos[1] + apos[2].toUpperCase() + apos[3] + apos[4].toUpperCase() + apos[5];
        // Leading punctuation such as "(" before the first letter
        var lead = /^([^A-Za-zÀ-ɏ]*)(.*)$/.exec(lower);
        return lead[1] + capitalise(lead[2]);
    }

    function titleCase(value) {
        if (value === null || value === undefined) return '';
        var text = String(value).replace(POSTCODE, function (all, outward, inward) {
            return outward.toUpperCase() + ' ' + inward.toUpperCase();
        });
        var wordIndex = 0;
        return text.replace(/\S+/g, function (word) {
            var first = wordIndex === 0;
            wordIndex++;
            return word.split('-').map(function (part, i) {
                return titleCasePart(part, first && i === 0);
            }).join('-');
        });
    }

    var KBF = window.KBF = window.KBF || {};
    KBF.MONTHS_SHORT = MONTHS_SHORT;
    KBF.MONTHS_LONG = MONTHS_LONG;
    KBF.WEEKDAYS_SHORT = WEEKDAYS_SHORT;
    KBF.WEEKDAYS_LONG = WEEKDAYS_LONG;
    KBF.parseDate = parseDate;
    KBF.formatDate = formatDate;
    KBF.formatTime = formatTime;
    KBF.titleCase = titleCase;
    KBF.storage = storage;
    KBF.showLoadError = showLoadError;
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
    window.titleCase = titleCase;
})(window);
