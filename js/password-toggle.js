/* ============================================
 * KBF-UK PASSWORD SHOW/HIDE BUTTON
 * Used by the staff sign-in pages. Markup:
 *
 *   <div class="pw-wrap">
 *       <input id="password" type="password" class="auth-input">
 *       <button type="button" class="pw-toggle" aria-controls="password"></button>
 *   </div>
 *
 * KBFPasswordToggle.init() fills every .pw-toggle with an eye icon and sets
 * aria-label ("Show password" / "Hide password") and aria-pressed.
 * KBFPasswordToggle.hideAll() puts every field back to hidden (used after
 * a form is sent). No dependencies; icons are constant markup only.
 * ============================================ */
(function () {
    'use strict';

    var EYE = '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>';
    var EYE_OFF = '<path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c4.94 0 9.17 3.1 10.94 7.35a1 1 0 0 1 0 .7 10.75 10.75 0 0 1-1.44 2.49"/><path d="M14.08 14.16a3 3 0 0 1-4.24-4.24"/><path d="M17.48 17.5A10.75 10.75 0 0 1 2.06 12.35a1 1 0 0 1 0-.7 10.76 10.76 0 0 1 4.45-5.14"/><path d="m2 2 20 20"/>';

    function svg(paths) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + paths + '</svg>';
    }

    function setState(button, input, shown) {
        input.type = shown ? 'text' : 'password';
        button.setAttribute('aria-pressed', shown ? 'true' : 'false');
        button.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
        button.innerHTML = svg(shown ? EYE_OFF : EYE);
    }

    function bind(button) {
        if (button.getAttribute('data-pw-ready') === '1') return;
        var input = document.getElementById(button.getAttribute('aria-controls') || '');
        if (!input) return;
        button.type = 'button';
        button.setAttribute('data-pw-ready', '1');
        setState(button, input, false);
        button.addEventListener('click', function () {
            var shown = input.type === 'password';
            setState(button, input, shown);
            input.focus();
        });
    }

    function init(root) {
        var buttons = (root || document).querySelectorAll('.pw-toggle');
        for (var i = 0; i < buttons.length; i++) bind(buttons[i]);
    }

    function hideAll(root) {
        var buttons = (root || document).querySelectorAll('.pw-toggle[data-pw-ready="1"]');
        for (var i = 0; i < buttons.length; i++) {
            var input = document.getElementById(buttons[i].getAttribute('aria-controls') || '');
            if (input) setState(buttons[i], input, false);
        }
    }

    window.KBFPasswordToggle = { init: init, hideAll: hideAll };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})();
