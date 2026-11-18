/* ============================================
 * KBF-UK NEWS HELPERS
 * Shared by the home page (index.html) and the News page (news.html).
 *
 *   KBFNews.isPromotion(post)      Title contains "Player Promotion:" (any case)
 *   KBFNews.promotionName(post)    the text after "Player Promotion:", emoji removed
 *   KBFNews.promotionGrades(post)  { from, to } from "Category Change: {old} → {new}"
 *                                  in the Content, or null when it cannot be read
 *   KBFNews.promotionInitials(name)
 *   KBFNews.plainText(html)        HTML removed, nothing executed or loaded
 *   KBFNews.excerpt(post)          Content as plain text, spaces collapsed
 *   KBFNews.postDate(post)         PublishedDate, falling back to CreatedDate
 *   KBFNews.formatDate(post)       "TUE 26 MAY 2026"
 *   KBFNews.postHref(post)         /post?id={AnnouncementId}
 *   KBFNews.icon(name, className)  inline SVG (constant markup only)
 *   KBFNews.el(tag, className, text)
 *
 * Every value from a post is written with textContent, never as HTML.
 * ============================================ */
(function () {
    'use strict';

    // Static icon markup (constants only, never post text)
    var ICONS = {
        newspaper: '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/>',
        calendar: '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        arrow: '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        trophy: '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        chevron: '<path d="m9 18 6-6-6-6"/>',
        search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        list: '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
        grid: '<rect width="7" height="7" x="3" y="3"/><rect width="7" height="7" x="14" y="3"/><rect width="7" height="7" x="14" y="14"/><rect width="7" height="7" x="3" y="14"/>',
        close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        layers: '<path d="m12 2 10 5-10 5L2 7l10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>'
    };

    var PROMO_PREFIX = 'player promotion:';
    var DAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
    var MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    function icon(name, className) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        if (className) svg.setAttribute('class', className);
        svg.innerHTML = ICONS[name] || '';
        return svg;
    }

    // Create an element; text is always set with textContent
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    // HTML -> plain text without executing scripts or loading images
    function plainText(html) {
        if (!html) return '';
        var doc = new DOMParser().parseFromString(String(html), 'text/html');
        return doc.body ? (doc.body.textContent || '') : '';
    }

    function excerpt(post) {
        return plainText(post.Content).replace(/\s+/g, ' ').trim();
    }

    // Local calendar date of a post (PublishedDate, then CreatedDate)
    function postDate(post) {
        var raw = String(post.PublishedDate || post.CreatedDate || '');
        var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (!match) return null;
        return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]),
            Number(match[4] || 0), Number(match[5] || 0), Number(match[6] || 0));
    }

    // "TUE 26 MAY 2026"
    function formatDate(post) {
        var date = post instanceof Date ? post : postDate(post);
        if (!date || isNaN(date.getTime())) return '';
        return DAYS[date.getDay()] + ' ' + date.getDate() + ' ' + MONTHS[date.getMonth()] + ' ' + date.getFullYear();
    }

    function postHref(post) {
        return '/post?id=' + encodeURIComponent(post.AnnouncementId);
    }

    function isPromotion(post) {
        return String(post.Title || '').toLowerCase().indexOf(PROMO_PREFIX) !== -1;
    }

    function promotionName(post) {
        var title = String(post.Title || '');
        var index = title.toLowerCase().indexOf(PROMO_PREFIX);
        var name = index >= 0 ? title.slice(index + PROMO_PREFIX.length) : title;
        return name.replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu, '').replace(/\s+/g, ' ').trim();
    }

    // "Category Change: C (Intermediate - High) → C+ Grade" -> { from: 'C', to: 'C+' }
    // The short grade at the start of each name is read (A, B, C+, C, D+, D, E
    // or any other short name of 1 to 3 characters), so new or renamed grades
    // work without a fixed list.
    function promotionGrades(post) {
        var text = plainText(post.Content);
        var match = text.match(/Category Change:\s*([A-Za-z0-9][A-Za-z0-9+]{0,2})(?![A-Za-z0-9])[^\n→]*?(?:→|->)\s*([A-Za-z0-9][A-Za-z0-9+]{0,2})(?![A-Za-z0-9])/);
        if (!match) return null;
        return { from: match[1].toUpperCase(), to: match[2].toUpperCase() };
    }

    function promotionInitials(name) {
        var words = String(name || '').split(' ').filter(Boolean);
        if (words.length === 0) return '';
        var first = words[0].charAt(0);
        var last = words.length > 1 ? words[words.length - 1].charAt(0) : '';
        return (first + last).toUpperCase();
    }

    window.KBFNews = {
        icon: icon,
        el: el,
        plainText: plainText,
        excerpt: excerpt,
        postDate: postDate,
        formatDate: formatDate,
        postHref: postHref,
        isPromotion: isPromotion,
        promotionName: promotionName,
        promotionGrades: promotionGrades,
        promotionInitials: promotionInitials
    };
})();
