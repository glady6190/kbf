/* ============================================
 * KBF-UK SIX-DIGIT CODE INPUT
 * Six single-digit boxes for authenticator codes.
 *
 *   var input = KBFCodeInput.mount(document.getElementById('code'), {
 *       label: 'Code',                         // read out for the group
 *       onComplete: function (code) { ... }    // all six boxes filled
 *   });
 *   input.value()          '123456' (or fewer digits while typing)
 *   input.clear()          empty every box
 *   input.focus()          focus the first empty box
 *   input.setDisabled(true|false)
 *
 * Digits only. Typing moves to the next box, Backspace on an empty box goes
 * back, arrow keys move, and pasting a 6-digit code (or the phone's
 * one-time-code autofill) into any box fills all six. No dependencies.
 * ============================================ */
(function () {
    'use strict';

    var LENGTH = 6;
    var counter = 0;

    function injectStyles() {
        if (document.getElementById('kbf-code-input-styles')) return;
        var style = document.createElement('style');
        style.id = 'kbf-code-input-styles';
        style.textContent =
            '.kbf-code-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}' +
            '.kbf-code-row{display:flex;justify-content:center;gap:.375rem;width:100%;max-width:24rem;margin:0 auto;}' +
            '.kbf-code-box{flex:1 1 0;min-width:0;max-width:3.5rem;height:3.5rem;padding:0;text-align:center;' +
                'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:1.5rem;font-weight:900;color:#fff;' +
                'background:#1e293b;border:2px solid #374151;border-radius:0;caret-color:var(--theme-primary);' +
                'transition:border-color .15s ease,box-shadow .15s ease;-webkit-appearance:none;appearance:none;}' +
            '.kbf-code-box:focus{outline:none;border-color:var(--theme-primary);box-shadow:0 0 0 3px var(--theme-primary);}' +
            '.kbf-code-box.kbf-code-filled{border-color:#6b7280;}' +
            '.kbf-code-box:disabled{opacity:.5;cursor:not-allowed;}' +
            '@media (min-width:640px){.kbf-code-row{gap:.625rem;}.kbf-code-box{height:4rem;font-size:1.75rem;}}';
        document.head.appendChild(style);
    }

    function digitsOnly(text) {
        return String(text || '').replace(/\D+/g, '');
    }

    function mount(container, options) {
        var opts = options || {};
        var onComplete = typeof opts.onComplete === 'function' ? opts.onComplete : null;
        var id = 'kbf-code-' + (++counter);
        var lastFired = null;

        injectStyles();
        container.innerHTML = '';

        var label = document.createElement('span');
        label.id = id + '-label';
        label.className = 'kbf-code-sr';
        label.textContent = opts.label || 'Code';

        var row = document.createElement('div');
        row.className = 'kbf-code-row';
        row.setAttribute('role', 'group');
        row.setAttribute('aria-labelledby', label.id);

        var boxes = [];
        for (var i = 0; i < LENGTH; i++) {
            var box = document.createElement('input');
            box.type = 'text';
            box.className = 'kbf-code-box';
            box.setAttribute('inputmode', 'numeric');
            box.setAttribute('pattern', '[0-9]*');
            box.setAttribute('maxlength', '1');
            box.setAttribute('autocomplete', i === 0 ? 'one-time-code' : 'off');
            box.setAttribute('autocorrect', 'off');
            box.setAttribute('autocapitalize', 'off');
            box.setAttribute('spellcheck', 'false');
            box.setAttribute('aria-label', 'Digit ' + (i + 1) + ' of ' + LENGTH);
            box.id = id + '-' + i;
            boxes.push(box);
            row.appendChild(box);
        }

        container.appendChild(label);
        container.appendChild(row);

        function value() {
            return boxes.map(function (b) { return b.value; }).join('');
        }

        function refresh() {
            boxes.forEach(function (b) { b.classList.toggle('kbf-code-filled', b.value !== ''); });
        }

        function checkComplete() {
            refresh();
            var code = value();
            if (code.length === LENGTH && /^\d{6}$/.test(code)) {
                if (code !== lastFired) {
                    lastFired = code;
                    if (onComplete) onComplete(code);
                }
            } else {
                lastFired = null;
            }
        }

        function focusBox(index) {
            var target = boxes[Math.max(0, Math.min(LENGTH - 1, index))];
            target.focus();
            try { target.select(); } catch (e) { /* ignore */ }
        }

        // Put several digits in, starting at a box (a full code always starts at the first)
        function fill(start, digits) {
            if (digits.length >= LENGTH) {
                digits = digits.slice(0, LENGTH);
                start = 0;
            }
            for (var j = 0; j < digits.length && start + j < LENGTH; j++) {
                boxes[start + j].value = digits.charAt(j);
            }
            var next = Math.min(start + digits.length, LENGTH - 1);
            focusBox(next);
            checkComplete();
        }

        boxes.forEach(function (box, index) {
            box.addEventListener('focus', function () {
                try { box.select(); } catch (e) { /* ignore */ }
            });

            box.addEventListener('input', function () {
                var digits = digitsOnly(box.value);
                if (digits.length === 0) {
                    box.value = '';
                    checkComplete();
                    return;
                }
                if (digits.length === 1) {
                    box.value = digits;
                    if (index < LENGTH - 1) focusBox(index + 1);
                    checkComplete();
                    return;
                }
                // Autofill or a browser that ignores maxlength
                box.value = '';
                fill(index, digits);
            });

            box.addEventListener('paste', function (e) {
                var text = '';
                try { text = (e.clipboardData || window.clipboardData).getData('text'); } catch (err) { text = ''; }
                var digits = digitsOnly(text);
                e.preventDefault();
                if (digits.length) fill(index, digits);
            });

            box.addEventListener('keydown', function (e) {
                var key = e.key;
                if (key === 'Backspace') {
                    if (box.value === '' && index > 0) {
                        e.preventDefault();
                        boxes[index - 1].value = '';
                        focusBox(index - 1);
                        checkComplete();
                    }
                    return;
                }
                if (key === 'Delete') {
                    e.preventDefault();
                    box.value = '';
                    checkComplete();
                    return;
                }
                if (key === 'ArrowLeft') { e.preventDefault(); if (index > 0) focusBox(index - 1); return; }
                if (key === 'ArrowRight') { e.preventDefault(); if (index < LENGTH - 1) focusBox(index + 1); return; }
                if (key === 'Home') { e.preventDefault(); focusBox(0); return; }
                if (key === 'End') { e.preventDefault(); focusBox(LENGTH - 1); return; }

                if (e.ctrlKey || e.metaKey || e.altKey) return;
                if (key && key.length === 1) {
                    if (!/\d/.test(key)) { e.preventDefault(); return; }
                    // Replace a digit already in the box, even if it is not selected
                    var selected = box.selectionStart !== box.selectionEnd;
                    if (box.value !== '' && !selected) {
                        e.preventDefault();
                        box.value = key;
                        if (index < LENGTH - 1) focusBox(index + 1);
                        checkComplete();
                    }
                }
            });
        });

        return {
            value: value,
            clear: function () {
                boxes.forEach(function (b) { b.value = ''; });
                lastFired = null;
                refresh();
            },
            focus: function () {
                var code = value();
                var firstEmpty = 0;
                for (var k = 0; k < LENGTH; k++) {
                    if (boxes[k].value === '') { firstEmpty = k; break; }
                    if (k === LENGTH - 1) firstEmpty = LENGTH - 1;
                }
                focusBox(code.length === 0 ? 0 : firstEmpty);
            },
            setDisabled: function (disabled) {
                boxes.forEach(function (b) { b.disabled = !!disabled; });
            }
        };
    }

    window.KBFCodeInput = { mount: mount };
})();
