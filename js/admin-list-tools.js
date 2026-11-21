/* ============================================
 * KBF-UK ADMIN LIST TOOLS (phones and tablets, below 1024px)
 *
 * Shared parts for admin pages with tabs, filters or page actions. Desktop
 * (1024px and wider) is untouched: everything here is hidden there and the
 * page's own controls are put back where they were. Styles are in the
 * shared stylesheet (assets/css/tailwind.input.css, "ADMIN ON PHONES").
 * Load after js/escape.js and js/kbf-dialog.js.
 *
 * 1. Page header: title (26px, theme colour, short bar), the page's main
 *    action as a compact button with a plus icon, and a "more" button with
 *    the page's other actions. The page's own heading band is hidden.
 *      KBFListTools.header({
 *          band: element,              // the page's heading band
 *          title: 'People',            // default: the band's <h1>
 *          subtitle: '...',            // default: the band's first <p>
 *          primary: { label: 'Player', target: buttonOrLink },   // icon: 'edit'
 *                                      // instead of the plus (optional)
 *          more: [{ label: 'Export', icon: 'download', target: el }, ...]
 *      });
 *    target: an existing button or link (clicked for you; hidden when it is
 *    hidden). Or give onClick (function) or href instead.
 *
 * 2. Tabs: one row that scrolls sideways, with a fade on the right while
 *    more tabs are hidden; the active tab is scrolled into view.
 *      KBFListTools.tabs(tablistElement);
 *
 * 3. Search and filters: the search box and a "Filters" button side by
 *    side, active filters as removable tags with "Clear all", and a sheet
 *    from the bottom holding the page's own filter and sort controls.
 *      var tools = KBFListTools.filters({
 *          anchor: element,          // the page's search and filter block
 *          search: inputElement,     // moved into the toolbar
 *          controls: [
 *              { el: selectElement, label: 'Grade' },
 *              { el: sortSelect, label: 'Sort', sort: true },   // no tag
 *              { block: element, label: 'Dates' }               // moved as is
 *          ],
 *          extra: [element],         // shown under the toolbar (optional)
 *          count: function () { return total; },   // live result count
 *          noun: ['player', 'players'],
 *          shortPlaceholder: 'Search players',   // search placeholder on
 *                                      // phones; desktop keeps the field's own
 *          onClearAll: function () {}  // optional; default: every changed
 *                                      // select back to its default
 *      });
 *      tools.refresh();   // after the list has loaded (count and tags)
 *      tools.setPlaceholder(long, short);   // when the page changes the
 *                                      // search wording (for example per tab)
 *    The controls keep their ids and listeners; they are moved, not copied.
 * ============================================ */
(function (window, document) {
    'use strict';

    var PHONE = '(max-width: 1023.98px)';
    var ICONS = {
        plus: 'M12 4v16m8-8H4',
        dots: 'M5 12h.01M12 12h.01M19 12h.01',
        filter: 'M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z',
        search: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
        close: 'M6 18L18 6M6 6l12 12',
        download: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
        users: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        user: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        clock: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        list: 'M4 6h16M4 10h16M4 14h16M4 18h16',
        trophy: 'M8 21h8m-4-4v4m-5-17h10v5a5 5 0 01-10 0V4zM7 6H4v1a3 3 0 003 3m10-4h3v1a3 3 0 01-3 3',
        mail: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        edit: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        chart: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        eye: 'M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z'
    };

    function svg(name, extraClass, width) {
        var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        s.setAttribute('viewBox', '0 0 24 24');
        s.setAttribute('fill', 'none');
        s.setAttribute('stroke', 'currentColor');
        s.setAttribute('aria-hidden', 'true');
        s.setAttribute('focusable', 'false');
        if (extraClass) s.setAttribute('class', extraClass);
        var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        p.setAttribute('stroke-linecap', 'round');
        p.setAttribute('stroke-linejoin', 'round');
        p.setAttribute('stroke-width', String(width || 2));
        p.setAttribute('d', ICONS[name] || ICONS.list);
        s.appendChild(p);
        return s;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function isPhone() {
        try { return window.matchMedia(PHONE).matches; } catch (e) { return window.innerWidth < 1024; }
    }

    function onPhoneChange(fn) {
        try { window.matchMedia(PHONE).addEventListener('change', function (e) { fn(e.matches); }); }
        catch (e) { /* older browsers: the layout chosen at load stays */ }
    }

    function reducedMotion() {
        try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; }
    }

    // An action's target is "available" when it is not hidden or disabled
    function available(item) {
        if (!item) return false;
        if (item.onClick || item.href) return item.visible ? !!item.visible() : true;
        var t = item.target;
        if (!t || !t.isConnected) return false;
        if (t.hidden || t.classList.contains('hidden') || t.disabled) return false;
        if (t.closest('.hidden:not(.kbf-ph-band):not(.kbf-lt-anchor)')) return false;
        return true;
    }

    function run(item) {
        if (item.onClick) { item.onClick(); return; }
        if (item.href) { window.location.href = item.href; return; }
        if (item.target) item.target.click();
    }

    var idCounter = 0;
    function uid(prefix) { return prefix + '-' + (++idCounter); }

    // ============================================
    // 1. PAGE HEADER
    // ============================================
    function header(opts) {
        var band = opts.band;
        if (!band) return null;
        var h1 = band.querySelector('h1');
        var sub = band.querySelector('p');
        var title = opts.title || (h1 ? h1.textContent.trim() : document.title);
        var subtitle = opts.subtitle !== undefined ? opts.subtitle : (sub ? sub.textContent.trim() : '');

        var box = el('div', 'kbf-ph');
        var row = el('div', 'kbf-ph-row');
        var left = el('div', 'min-w-0');
        // The visible heading on phones; the band (with its own h1) is hidden
        var heading = el('h1', 'kbf-ph-title', title);
        left.appendChild(heading);
        left.appendChild(el('div', 'kbf-ph-bar'));
        row.appendChild(left);

        var actions = el('div', 'kbf-ph-actions');
        var moreItems = (opts.more || []).filter(Boolean);
        var moreBtn = null;
        var pop = null;
        if (moreItems.length) {
            moreBtn = el('button', 'kbf-ph-more');
            moreBtn.type = 'button';
            moreBtn.setAttribute('aria-label', 'More actions');
            moreBtn.setAttribute('aria-haspopup', 'menu');
            moreBtn.setAttribute('aria-expanded', 'false');
            moreBtn.appendChild(svg('dots', 'w-6 h-6', 3));
            pop = el('div', 'kbf-pop');
            pop.id = uid('kbf-pop');
            pop.setAttribute('role', 'menu');
            pop.hidden = true;
            moreBtn.setAttribute('aria-controls', pop.id);
            actions.appendChild(moreBtn);
            actions.appendChild(pop);
        }
        var primaryBtn = null;
        if (opts.primary) {
            var p = opts.primary;
            primaryBtn = el(p.href && !p.target ? 'a' : 'button', 'kbf-ph-primary');
            if (primaryBtn.tagName === 'A') primaryBtn.href = p.href; else primaryBtn.type = 'button';
            primaryBtn.appendChild(svg(p.icon || 'plus', 'w-5 h-5', p.icon ? 2.5 : 3));
            primaryBtn.appendChild(document.createTextNode(p.label));
            if (p.ariaLabel) primaryBtn.setAttribute('aria-label', p.ariaLabel);
            if (primaryBtn.tagName !== 'A') primaryBtn.addEventListener('click', function () { run(p); });
            actions.appendChild(primaryBtn);
        }
        row.appendChild(actions);
        box.appendChild(row);
        if (subtitle) box.appendChild(el('p', 'kbf-ph-sub', subtitle));

        band.parentNode.insertBefore(box, band);
        band.classList.add('kbf-ph-band');

        // Desktop keeps the page's own header: the phone copy (and its
        // buttons) is fully hidden there, so nothing in it can be reached
        function showForScreen(phone) {
            box.hidden = !phone;
            if (!phone && pop && !pop.hidden) closeMenu(false);
        }

        // ---- the "more" menu ----
        function menuItems() { return pop ? Array.prototype.slice.call(pop.querySelectorAll('.kbf-pop-item')) : []; }
        function closeMenu(focusBack) {
            if (!pop || pop.hidden) return;
            pop.hidden = true;
            moreBtn.setAttribute('aria-expanded', 'false');
            if (focusBack) moreBtn.focus();
        }
        function openMenu(focusLast) {
            pop.textContent = '';
            moreItems.filter(available).forEach(function (item) {
                var b = el('button', 'kbf-pop-item');
                b.type = 'button';
                b.setAttribute('role', 'menuitem');
                b.tabIndex = -1;
                b.appendChild(svg(item.icon || 'list'));
                b.appendChild(document.createTextNode(item.label));
                b.addEventListener('click', function () { closeMenu(false); run(item); });
                pop.appendChild(b);
            });
            var list = menuItems();
            if (!list.length) return;
            pop.hidden = false;
            moreBtn.setAttribute('aria-expanded', 'true');
            (focusLast ? list[list.length - 1] : list[0]).focus();
        }
        if (moreBtn) {
            moreBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (pop.hidden) openMenu(false); else closeMenu(true);
            });
            moreBtn.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    openMenu(e.key === 'ArrowUp');
                }
            });
            pop.addEventListener('keydown', function (e) {
                var list = menuItems();
                var i = list.indexOf(document.activeElement);
                if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
                else if (e.key === 'Home') { e.preventDefault(); list[0].focus(); }
                else if (e.key === 'End') { e.preventDefault(); list[list.length - 1].focus(); }
                else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(true); }
                else if (e.key === 'Tab') { closeMenu(false); }
            });
            document.addEventListener('click', function (e) {
                if (!pop.hidden && !pop.contains(e.target) && e.target !== moreBtn) closeMenu(false);
            });
        }

        // Show the buttons only while their actions are available
        function refresh() {
            if (primaryBtn) primaryBtn.classList.toggle('hidden', !available(opts.primary));
            if (moreBtn) moreBtn.classList.toggle('hidden', !moreItems.some(available));
        }
        var targets = [];
        if (opts.primary && opts.primary.target) targets.push(opts.primary.target);
        moreItems.forEach(function (m) { if (m.target) targets.push(m.target); });
        if (window.MutationObserver && targets.length) {
            var mo = new MutationObserver(refresh);
            targets.forEach(function (t) { mo.observe(t, { attributes: true, attributeFilter: ['class', 'hidden', 'disabled'] }); });
        }
        refresh();
        showForScreen(isPhone());
        onPhoneChange(showForScreen);
        return { refresh: refresh, element: box };
    }

    // ============================================
    // 2. TABS
    // ============================================
    function tabs(list) {
        if (!list || list.__kbfTabs) return null;
        list.__kbfTabs = true;
        var wrap = el('div', 'kbf-tabs-wrap');
        list.parentNode.insertBefore(wrap, list);
        wrap.appendChild(list);
        var fade = el('span', 'kbf-tabs-fade');
        fade.setAttribute('aria-hidden', 'true');
        wrap.appendChild(fade);
        list.classList.add('kbf-tabs');

        function updateFade() {
            var more = isPhone() && list.scrollLeft + list.clientWidth < list.scrollWidth - 2;
            wrap.classList.toggle('has-more', more);
        }
        function showActive(smooth) {
            if (!isPhone()) return;
            var active = list.querySelector('[role="tab"][aria-selected="true"]') ||
                list.querySelector('[role="tab"].is-active, [role="tab"].active');
            if (!active || active.classList.contains('hidden')) return;
            var left = active.offsetLeft - 16;
            var right = active.offsetLeft + active.offsetWidth + 16 - list.clientWidth;
            var target = list.scrollLeft;
            if (left < list.scrollLeft) target = left;
            else if (right > list.scrollLeft) target = right;
            if (target !== list.scrollLeft) {
                try { list.scrollTo({ left: Math.max(0, target), behavior: smooth && !reducedMotion() ? 'smooth' : 'auto' }); }
                catch (e) { list.scrollLeft = Math.max(0, target); }
            }
            updateFade();
        }
        list.addEventListener('scroll', updateFade, { passive: true });
        window.addEventListener('resize', updateFade);
        onPhoneChange(function () { updateFade(); showActive(false); });
        if (window.MutationObserver) {
            new MutationObserver(function () { showActive(true); updateFade(); })
                .observe(list, { subtree: true, attributes: true, attributeFilter: ['aria-selected', 'class'], childList: true });
        }
        // On load (after fonts and the page's own set-up)
        window.setTimeout(function () { showActive(false); }, 0);
        window.addEventListener('load', function () { showActive(false); });
        return { refresh: function () { showActive(false); } };
    }

    // ============================================
    // 3. SEARCH, FILTERS AND THE FILTERS SHEET
    // ============================================
    function defaultOf(select, given) {
        if (given !== undefined) return String(given);
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].defaultSelected) return select.options[i].value;
        }
        return select.options.length ? select.options[0].value : '';
    }

    function filters(opts) {
        var anchor = opts.anchor;
        if (!anchor) return { refresh: function () {}, setPlaceholder: function () {} };
        var noun = opts.noun || ['result', 'results'];
        var controls = (opts.controls || []).filter(function (c) { return c && (c.el || c.block); });
        controls.forEach(function (c) {
            if (c.el && c.el.tagName === 'SELECT') c.def = defaultOf(c.el, c.defaultValue);
        });

        // ---- toolbar (phones) ----
        var bar = el('div', 'kbf-lt');
        var searchBox = el('div', 'kbf-lt-search');
        searchBox.appendChild(svg('search'));
        bar.appendChild(searchBox);
        var filterBtn = el('button', 'kbf-lt-btn');
        filterBtn.type = 'button';
        filterBtn.setAttribute('aria-haspopup', 'dialog');
        filterBtn.appendChild(svg('filter'));
        filterBtn.appendChild(document.createTextNode('Filters'));
        var badge = el('span', 'kbf-lt-badge hidden');
        badge.setAttribute('aria-hidden', 'true');
        filterBtn.appendChild(badge);
        bar.appendChild(filterBtn);
        if (!controls.length) filterBtn.classList.add('hidden');
        if (!opts.search) searchBox.classList.add('hidden');

        var tags = el('div', 'kbf-lt-tags hidden');
        tags.setAttribute('aria-label', 'Active filters');
        tags.setAttribute('role', 'group');
        var extra = el('div', 'kbf-lt-extra');
        if (!opts.extra || !opts.extra.length) extra.classList.add('hidden');

        anchor.parentNode.insertBefore(bar, anchor);
        anchor.parentNode.insertBefore(tags, anchor);
        anchor.parentNode.insertBefore(extra, anchor);

        // ---- sheet ----
        var backdrop = el('div', 'kbf-sheet-backdrop');
        backdrop.setAttribute('data-kbf-backdrop', '');
        var sheet = el('div', 'kbf-sheet');
        var titleId = uid('kbf-sheet-title');
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');
        sheet.setAttribute('aria-labelledby', titleId);
        var grab = el('div', 'kbf-sheet-grab');
        var handle = el('div', 'kbf-sheet-handle');
        handle.setAttribute('aria-hidden', 'true');
        grab.appendChild(handle);
        var head = el('div', 'kbf-sheet-head');
        var title = el('h2', 'kbf-sheet-title', 'Filters');
        title.id = titleId;
        title.tabIndex = -1;
        var clearInSheet = el('button', 'kbf-lt-clear', 'Clear all');
        clearInSheet.type = 'button';
        head.appendChild(title);
        head.appendChild(clearInSheet);
        grab.appendChild(head);
        sheet.appendChild(grab);
        var body = el('div', 'kbf-sheet-body');
        sheet.appendChild(body);
        var showBtn = el('button', 'kbf-sheet-show');
        showBtn.type = 'button';
        sheet.appendChild(showBtn);
        document.body.appendChild(backdrop);
        document.body.appendChild(sheet);

        // One labelled slot per control in the sheet
        controls.forEach(function (c) {
            var slot = el('div');
            if (c.label) {
                var lab = el('label', 'kbf-sheet-label', c.label);
                var field = c.el || null;
                if (field && field.id) lab.htmlFor = field.id;
                else if (!field) lab = el('p', 'kbf-sheet-label', c.label);
                slot.appendChild(lab);
            }
            c.slot = slot;
            body.appendChild(slot);
        });

        // ---- search wording: short on phones, the field's own on desktop ----
        var longPlaceholder = opts.search ? opts.search.placeholder : '';
        var shortPlaceholder = opts.shortPlaceholder || '';
        function applyPlaceholder() {
            if (!opts.search) return;
            var text = mounted && shortPlaceholder ? shortPlaceholder : longPlaceholder;
            if (text) opts.search.placeholder = text;
        }
        function setPlaceholder(longText, shortText) {
            longPlaceholder = longText || '';
            shortPlaceholder = shortText || '';
            applyPlaceholder();
        }

        // ---- moving the page's controls between desktop and phone ----
        var mounted = false;
        var homes = [];
        function moveTo(node, parent) {
            var mark = document.createComment('kbf-list-tools');
            node.parentNode.insertBefore(mark, node);
            homes.push({ node: node, mark: mark });
            parent.appendChild(node);
        }
        function mount() {
            if (mounted) return;
            mounted = true;
            if (opts.search) {
                if (!opts.search.getAttribute('aria-label')) {
                    var l = opts.search.id ? document.querySelector('label[for="' + opts.search.id + '"]') : null;
                    opts.search.setAttribute('aria-label', (l && l.textContent.trim()) || opts.search.placeholder || 'Search');
                }
                moveTo(opts.search, searchBox);
                applyPlaceholder();
            }
            controls.forEach(function (c) { moveTo(c.el || c.block, c.slot); });
            (opts.extra || []).forEach(function (x) { if (x) moveTo(x, extra); });
            anchor.classList.add('kbf-lt-anchor');
            refresh();
        }
        function unmount() {
            if (!mounted) return;
            mounted = false;
            closeSheet(false);
            homes.forEach(function (h) {
                if (h.mark.parentNode) {
                    h.mark.parentNode.insertBefore(h.node, h.mark);
                    h.mark.parentNode.removeChild(h.mark);
                }
            });
            homes = [];
            anchor.classList.remove('kbf-lt-anchor');
            applyPlaceholder();
        }

        // ---- active filters ----
        // Text and date fields (given as el, or inside a block) count as
        // active while they hold a value; selects while not on their default
        function fieldsOf(c) {
            if (c.el && c.el.tagName !== 'SELECT') return [c.el];
            if (c.block) return Array.prototype.slice.call(c.block.querySelectorAll('input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"])'));
            return [];
        }
        function isActive(c) {
            if (c.sort) return false;
            if (c.el && c.el.tagName === 'SELECT') return !c.el.disabled && c.el.value !== c.def;
            return fieldsOf(c).some(function (f) { return !f.disabled && String(f.value).trim() !== ''; });
        }
        function activeControls() { return controls.filter(isActive); }
        function fire(field) {
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function setValue(c, value) {
            if (c.el && c.el.tagName === 'SELECT') {
                c.el.value = value;
                fire(c.el);
                return;
            }
            fieldsOf(c).forEach(function (f) { if (f.value !== '') { f.value = ''; fire(f); } });
        }
        function tagText(c) {
            if (c.el && c.el.tagName === 'SELECT') {
                var opt = c.el.options[c.el.selectedIndex];
                return opt ? opt.textContent.trim() : c.el.value;
            }
            var values = fieldsOf(c).map(function (f) { return String(f.value).trim(); }).filter(Boolean);
            return (c.label ? c.label + ': ' : '') + values.join(' to ');
        }
        function clearAll() {
            if (opts.onClearAll) opts.onClearAll();
            else activeControls().forEach(function (c) { setValue(c, c.def); });
            refresh();
        }

        function refresh() {
            var active = activeControls();
            badge.textContent = String(active.length);
            badge.classList.toggle('hidden', !active.length);
            filterBtn.setAttribute('aria-label', active.length ? 'Filters, ' + active.length + ' active' : 'Filters');
            tags.textContent = '';
            active.forEach(function (c) {
                var text = tagText(c);
                var t = el('button', 'kbf-lt-tag');
                t.type = 'button';
                t.setAttribute('aria-label', 'Remove filter: ' + (c.label ? c.label + ', ' : '') + text);
                t.appendChild(el('span', '', text));
                t.appendChild(svg('close', '', 2.6));
                t.addEventListener('click', function () {
                    setValue(c, c.def);
                    refresh();
                    filterBtn.focus();
                });
                tags.appendChild(t);
            });
            if (active.length) {
                var clear = el('button', 'kbf-lt-clear', 'Clear all');
                clear.type = 'button';
                clear.addEventListener('click', function () { clearAll(); filterBtn.focus(); });
                tags.appendChild(clear);
            }
            tags.classList.toggle('hidden', !active.length);
            clearInSheet.classList.toggle('hidden', !active.length);
            var n = typeof opts.count === 'function' ? opts.count() : null;
            if (n === null || n === undefined || isNaN(Number(n))) {
                showBtn.textContent = 'Show ' + noun[1];
            } else {
                n = Number(n);
                showBtn.textContent = 'Show ' + n.toLocaleString('en-GB') + ' ' + (n === 1 ? noun[0] : noun[1]);
            }
        }

        // ---- opening and closing the sheet ----
        var open = false;
        function openSheet() {
            if (open) return;
            open = true;
            refresh();
            backdrop.classList.add('is-open');
            sheet.classList.add('is-open');
            sheet.style.transform = '';
            if (!reducedMotion()) {
                sheet.classList.add('is-entering');
                window.requestAnimationFrame(function () {
                    window.requestAnimationFrame(function () { sheet.classList.remove('is-entering'); });
                });
            }
            document.documentElement.classList.add('kbf-pm-lock');
            if (window.KBFDialog) {
                window.KBFDialog.open(sheet, { onClose: function () { closeSheet(true); }, initialFocus: title, opener: filterBtn });
            } else {
                title.focus();
            }
        }
        function closeSheet(focusBack) {
            if (!open) return;
            open = false;
            backdrop.classList.remove('is-open');
            sheet.classList.remove('is-open', 'is-entering');
            sheet.style.transform = '';
            document.documentElement.classList.remove('kbf-pm-lock');
            if (window.KBFDialog) window.KBFDialog.close(sheet, { returnFocus: focusBack !== false });
            else if (focusBack !== false) filterBtn.focus();
        }
        filterBtn.addEventListener('click', openSheet);
        backdrop.addEventListener('click', function () { closeSheet(true); });
        showBtn.addEventListener('click', function () { closeSheet(true); });
        clearInSheet.addEventListener('click', function () { clearAll(); title.focus(); });
        sheet.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !window.KBFDialog) { e.preventDefault(); closeSheet(true); }
        });
        // Any change inside the sheet: tags and count follow (the page's own
        // listeners load the list; tools.refresh() then updates the count)
        body.addEventListener('change', function () { window.setTimeout(refresh, 0); });

        // Swipe down on the handle or heading to close
        var startY = null;
        var dy = 0;
        grab.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) return;
            startY = e.touches[0].clientY;
            dy = 0;
            sheet.style.transition = 'none';
        }, { passive: true });
        grab.addEventListener('touchmove', function (e) {
            if (startY === null) return;
            dy = Math.max(0, e.touches[0].clientY - startY);
            sheet.style.transform = 'translateY(' + dy + 'px)';
        }, { passive: true });
        grab.addEventListener('touchend', function () {
            if (startY === null) return;
            startY = null;
            sheet.style.transition = '';
            if (dy > 80) closeSheet(true);
            else sheet.style.transform = '';
        });

        if (isPhone()) mount();
        onPhoneChange(function (phone) { if (phone) mount(); else unmount(); });
        refresh();
        return { refresh: refresh, open: openSheet, close: closeSheet, setPlaceholder: setPlaceholder };
    }

    window.KBFListTools = {
        header: header,
        tabs: tabs,
        filters: filters,
        isPhone: isPhone,
        icon: svg
    };
})(window, document);
