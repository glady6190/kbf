/* ============================================
 * KBF-UK PUBLIC SITE SETTINGS
 * Loads /api/public-settings.php once per page and fills in the footer:
 *   - the contact email (.kf-contact-email and every mailto: link to the
 *     old address, keeping any subject such as "Sponsorship enquiry")
 *   - Facebook and Instagram links, shown only when filled in
 * The markup keeps today's text, so the page reads correctly even when the
 * settings cannot be loaded.
 *
 *   KBFSiteSettings.ready.then(function (settings) { ... });   // null on failure
 *   KBFSiteSettings.get('home.tickerSpeed', 50);
 *   KBFSiteSettings.applyTo(element);   // fill the contact email in content added later
 *
 * Every value is written with textContent or as a checked URL.
 * ============================================ */
(function () {
    'use strict';

    var FALLBACK_EMAIL = 'admin@kbf-uk.org';
    var data = null;

    var ICONS = {
        facebook: 'M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z',
        instagram: 'M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37zM17.5 6.5h.01M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5z'
    };

    function get(path, fallback) {
        var node = data;
        var parts = String(path).split('.');
        for (var i = 0; i < parts.length; i++) {
            if (node === null || typeof node !== 'object' || !(parts[i] in node)) return fallback;
            node = node[parts[i]];
        }
        return (node === null || node === undefined || node === '') ? fallback : node;
    }

    function isEmail(value) {
        return typeof value === 'string' && /^[^\s@<>"']+@[^\s@<>"']+\.[^\s@<>"']+$/.test(value);
    }

    function isHttps(value) {
        return typeof value === 'string' && /^https:\/\/[^\s"'<>]+$/i.test(value);
    }

    function svgIcon(name) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        var path = document.createElementNS(ns, 'path');
        path.setAttribute('d', ICONS[name]);
        svg.appendChild(path);
        return svg;
    }

    // Contact email inside root (the whole page by default)
    function fillEmail(root) {
        var scope = root || document;
        var email = get('contact.email', FALLBACK_EMAIL);
        if (!isEmail(email)) return;
        scope.querySelectorAll('.kf-contact-email').forEach(function (el) { el.textContent = email; });
        scope.querySelectorAll('a[href^="mailto:' + FALLBACK_EMAIL + '"]').forEach(function (a) {
            var href = a.getAttribute('href');
            var query = href.indexOf('?') >= 0 ? href.slice(href.indexOf('?')) : '';
            a.setAttribute('href', 'mailto:' + email + query);
            if (a.textContent.trim() === FALLBACK_EMAIL) a.textContent = email;
        });
    }

    // For content added after the page loaded (such as the terms pop-up)
    function applyTo(root) {
        return ready.then(function () {
            if (data && root) fillEmail(root);
        });
    }

    function fillFooter() {
        fillEmail(document);

        var links = [
            { key: 'facebook', label: 'Facebook', url: get('social.facebook', '') },
            { key: 'instagram', label: 'Instagram', url: get('social.instagram', '') }
        ].filter(function (l) { return isHttps(l.url); });
        if (!links.length) return;

        document.querySelectorAll('.kf-contact').forEach(function (box) {
            if (box.querySelector('.kf-social')) return;
            var list = document.createElement('ul');
            list.className = 'kf-links kf-social';
            list.style.marginTop = '1rem';
            links.forEach(function (l) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'kf-link';
                a.href = l.url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.appendChild(svgIcon(l.key));
                a.appendChild(document.createTextNode(l.label));
                li.appendChild(a);
                list.appendChild(li);
            });
            box.appendChild(list);
        });
    }

    var ready = fetch('/api/public-settings.php', { credentials: 'include' })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json && json.success) data = json;
            return data;
        })
        .catch(function () { return null; });

    ready.then(function () {
        if (!data) return;
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fillFooter);
        } else {
            fillFooter();
        }
    });

    window.KBFSiteSettings = { ready: ready, get: get, applyTo: applyTo };
})();
