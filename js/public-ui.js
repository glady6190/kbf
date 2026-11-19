/* ============================================
 * KBF-UK public header behaviour (every public page)
 *   - "More" menu opens on click, tap and keyboard as well as hover.
 *     Escape closes it and returns focus to the "More" button.
 *   - The mobile menu button and the mobile "More" button keep
 *     aria-expanded in step with their menus; Escape closes the mobile menu.
 *   - Theme dots get an accessible name and aria-pressed.
 * Load with: <script src="/js/public-ui.js" defer></script>
 * ============================================ */
(function () {
    'use strict';

    function setExpanded(btn, open) {
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    // ---------- Desktop "More" menu ----------
    function initDropdown(dropdown, index) {
        var toggle = dropdown.querySelector('.dropdown-toggle');
        var menu = dropdown.querySelector('.dropdown-menu');
        if (!toggle || !menu) return null;

        if (!menu.id) menu.id = 'more-menu' + (index ? '-' + index : '');
        toggle.setAttribute('aria-controls', menu.id);
        if (toggle.tagName !== 'BUTTON') {
            // Older markup: an <a href="#"> toggle
            toggle.setAttribute('role', 'button');
            toggle.removeAttribute('onclick');
        }
        setExpanded(toggle, false);
        dropdown.classList.add('js-ready');

        function isOpen() { return dropdown.classList.contains('open'); }
        function open() { dropdown.classList.add('open'); setExpanded(toggle, true); }
        function close(returnFocus) {
            if (!isOpen()) return;
            dropdown.classList.remove('open');
            setExpanded(toggle, false);
            if (returnFocus) toggle.focus();
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (isOpen()) { close(false); } else { open(); }
        });

        toggle.addEventListener('keydown', function (e) {
            if (toggle.tagName !== 'BUTTON' && (e.key === ' ' || e.key === 'Enter')) {
                e.preventDefault();
                if (isOpen()) { close(false); } else { open(); }
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                open();
                var first = menu.querySelector('a, button');
                if (first) first.focus();
            }
        });

        menu.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            var items = Array.prototype.slice.call(menu.querySelectorAll('a, button'));
            var i = items.indexOf(document.activeElement);
            if (i < 0) return;
            e.preventDefault();
            var next = e.key === 'ArrowDown' ? Math.min(items.length - 1, i + 1) : i - 1;
            if (next < 0) { toggle.focus(); } else { items[next].focus(); }
        });

        // Close when focus leaves the menu (Tab past the last link)
        dropdown.addEventListener('focusout', function (e) {
            if (e.relatedTarget && !dropdown.contains(e.relatedTarget)) close(false);
        });

        return { el: dropdown, close: close, isOpen: isOpen };
    }

    // ---------- Mobile menu ----------
    function watchClass(target, className, onChange) {
        if (!target || !window.MutationObserver) return;
        new MutationObserver(function () {
            onChange(target.classList.contains(className));
        }).observe(target, { attributes: true, attributeFilter: ['class'] });
    }

    function init() {
        var dropdowns = [];
        document.querySelectorAll('nav .dropdown').forEach(function (d, i) {
            var api = initDropdown(d, i);
            if (api) dropdowns.push(api);
        });

        document.addEventListener('click', function (e) {
            dropdowns.forEach(function (d) {
                if (!d.el.contains(e.target)) d.close(false);
            });
        });

        var menuBtn = document.getElementById('mobile-menu-btn');
        var mobileMenu = document.getElementById('mobile-menu');
        if (menuBtn && mobileMenu) {
            if (!menuBtn.getAttribute('aria-label')) menuBtn.setAttribute('aria-label', 'Menu');
            menuBtn.setAttribute('type', 'button');
            menuBtn.setAttribute('aria-controls', 'mobile-menu');
            setExpanded(menuBtn, !mobileMenu.classList.contains('hidden'));
            watchClass(mobileMenu, 'hidden', function (hidden) { setExpanded(menuBtn, !hidden); });
        }

        var mobileMore = document.getElementById('mobile-dropdown-content');
        var mobileMoreBtn = document.getElementById('mobile-more-btn');
        if (mobileMore && mobileMoreBtn) {
            mobileMoreBtn.setAttribute('aria-controls', 'mobile-dropdown-content');
            setExpanded(mobileMoreBtn, mobileMore.classList.contains('active'));
            watchClass(mobileMore, 'active', function (active) { setExpanded(mobileMoreBtn, active); });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' && e.key !== 'Esc') return;
            dropdowns.forEach(function (d) {
                if (d.isOpen()) d.close(d.el.contains(document.activeElement));
            });
            if (menuBtn && mobileMenu && !mobileMenu.classList.contains('hidden')) {
                var inside = mobileMenu.contains(document.activeElement);
                mobileMenu.classList.add('hidden');
                if (inside) menuBtn.focus();
            }
        });

        // ---------- Theme dots ----------
        document.querySelectorAll('.theme-switcher-btn').forEach(function (btn) {
            var theme = btn.getAttribute('data-theme') || '';
            if (!btn.getAttribute('aria-label')) {
                var name = theme.charAt(0).toUpperCase() + theme.slice(1);
                btn.setAttribute('aria-label', name + ' theme');
            }
            if (!btn.getAttribute('title')) btn.setAttribute('title', btn.getAttribute('aria-label'));
            btn.setAttribute('type', 'button');
            btn.setAttribute('aria-pressed', btn.classList.contains('active') ? 'true' : 'false');
            watchClass(btn, 'active', function (active) {
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
