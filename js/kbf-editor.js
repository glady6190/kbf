/* ============================================
 * KBF-UK SMALL EMAIL EDITOR
 * A contenteditable editor for email wording: bold, italic, heading,
 * bulleted list, link and "Insert placeholder". No external libraries.
 *
 *   var editor = KBFEditor.create(hostElement, {
 *       label: 'Message',                  // accessible name
 *       placeholders: [{ key: 'first_name', description: 'First name' }],
 *       onChange: function () { ... }
 *   });
 *   editor.getHtml(); editor.setHtml(html); editor.insertText('{first_name}');
 *
 * The server cleans every saved message with an allowlist (p, br, b, strong,
 * i, em, h2, h3, ul, ol, li and http, https or mailto links), so this editor
 * only has to be convenient, not safe.
 * ============================================ */
(function () {
    'use strict';

    var ICONS = {
        bold: 'M6 4h8a4 4 0 010 8H6zM6 12h9a4 4 0 010 8H6z',
        italic: 'M10 4h8M6 20h8M14 4L10 20',
        heading: 'M6 4v16M18 4v16M6 12h12',
        list: 'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
        link: 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1'
    };

    var STYLE_ID = 'kbf-editor-styles';
    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent =
            '.kbf-ed{border:4px solid #374151;background:#1e293b;}' +
            '.kbf-ed:focus-within{border-color:var(--theme-primary);}' +
            '.kbf-ed-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem;padding:.375rem;border-bottom:2px solid #374151;background:#0f172a;}' +
            '.kbf-ed-btn{display:inline-flex;align-items:center;justify-content:center;min-width:2.25rem;height:2.25rem;padding:0 .5rem;color:#d1d5db;font-weight:900;font-size:.75rem;text-transform:uppercase;border:2px solid transparent;}' +
            '.kbf-ed-btn:hover,.kbf-ed-btn:focus-visible{color:#fff;border-color:#4b5563;outline:none;}' +
            '.kbf-ed-btn[aria-pressed="true"]{background:var(--theme-primary);color:#0F172A;}' +
            '.kbf-ed-sep{width:2px;height:1.5rem;background:#374151;margin:0 .25rem;}' +
            '.kbf-ed-select{background:#1e293b;border:2px solid #374151;color:#d1d5db;font-weight:700;font-size:.75rem;padding:.375rem .5rem;max-width:14rem;}' +
            '.kbf-ed-select:focus{outline:none;border-color:var(--theme-primary);}' +
            '.kbf-ed-area{min-height:14rem;max-height:32rem;overflow-y:auto;padding:1rem;color:#e5e7eb;font-weight:600;font-size:.875rem;line-height:1.6;outline:none;}' +
            '.kbf-ed-area h2{font-size:1.125rem;font-weight:900;text-transform:uppercase;color:var(--theme-primary);margin:.5rem 0;}' +
            '.kbf-ed-area h3{font-size:1rem;font-weight:900;color:#fff;margin:.5rem 0;}' +
            '.kbf-ed-area p{margin:.5rem 0;}' +
            '.kbf-ed-area ul,.kbf-ed-area ol{padding-left:1.5rem;margin:.5rem 0;}' +
            '.kbf-ed-area ul{list-style:disc;}.kbf-ed-area ol{list-style:decimal;}' +
            '.kbf-ed-area a{color:var(--theme-primary);text-decoration:underline;}' +
            '.kbf-ed-area b,.kbf-ed-area strong{color:#fff;}' +
            '.kbf-ed-link{display:none;flex-wrap:wrap;gap:.5rem;align-items:center;padding:.5rem;border-bottom:2px solid #374151;background:#0f172a;}' +
            '.kbf-ed-link.kbf-open{display:flex;}' +
            '.kbf-ed-link input{flex:1;min-width:12rem;background:#1e293b;border:2px solid #374151;color:#fff;padding:.375rem .5rem;font-weight:700;font-size:.75rem;}' +
            '.kbf-ed-link input:focus{outline:none;border-color:var(--theme-primary);}' +
            '.kbf-ed-link button{padding:.375rem .75rem;font-weight:900;font-size:.75rem;text-transform:uppercase;}' +
            '.kbf-ed-link .kbf-ed-ok{background:var(--theme-primary);color:#0F172A;}' +
            '.kbf-ed-link .kbf-ed-cancel{background:#1e293b;color:#d1d5db;border:2px solid #374151;}';
        document.head.appendChild(style);
    }

    function svg(name) {
        return '<svg class="w-4 h-4" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">' +
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="' + ICONS[name] + '"/></svg>';
    }

    // Shared escaper from /js/escape.js (must be loaded before this file).
    if (!window.KBF || typeof window.KBF.escapeHtml !== 'function') {
        throw new Error('kbf-editor.js needs /js/escape.js to be loaded first');
    }
    var escapeHtml = window.KBF.escapeHtml;

    var counter = 0;

    function create(host, options) {
        injectStyles();
        var opts = options || {};
        var id = 'kbf-ed-' + (++counter);
        var placeholders = Array.isArray(opts.placeholders) ? opts.placeholders : [];

        host.innerHTML =
            '<div class="kbf-ed">' +
                '<div class="kbf-ed-bar" role="toolbar" aria-label="Formatting">' +
                    '<button type="button" class="kbf-ed-btn" data-cmd="bold" aria-label="Bold" aria-pressed="false" title="Bold">' + svg('bold') + '</button>' +
                    '<button type="button" class="kbf-ed-btn" data-cmd="italic" aria-label="Italic" aria-pressed="false" title="Italic">' + svg('italic') + '</button>' +
                    '<button type="button" class="kbf-ed-btn" data-cmd="heading" aria-label="Heading" aria-pressed="false" title="Heading">' + svg('heading') + '</button>' +
                    '<button type="button" class="kbf-ed-btn" data-cmd="list" aria-label="Bulleted list" aria-pressed="false" title="Bulleted list">' + svg('list') + '</button>' +
                    '<button type="button" class="kbf-ed-btn" data-cmd="link" aria-label="Link" title="Link">' + svg('link') + '</button>' +
                    (placeholders.length ?
                        '<span class="kbf-ed-sep" aria-hidden="true"></span>' +
                        '<label class="sr-only" for="' + id + '-ph">Insert placeholder</label>' +
                        '<select class="kbf-ed-select" id="' + id + '-ph" data-placeholder-select>' +
                            '<option value="">Insert placeholder</option>' +
                            placeholders.map(function (p) {
                                return '<option value="' + escapeHtml(p.key) + '">{' + escapeHtml(p.key) + '}</option>';
                            }).join('') +
                        '</select>' : '') +
                '</div>' +
                '<div class="kbf-ed-link" data-link-row>' +
                    '<label class="sr-only" for="' + id + '-url">Link address</label>' +
                    '<input type="text" id="' + id + '-url" placeholder="https://... or {site_link}" autocomplete="off">' +
                    '<button type="button" class="kbf-ed-ok" data-link-ok>Add link</button>' +
                    '<button type="button" class="kbf-ed-cancel" data-link-cancel>Cancel</button>' +
                '</div>' +
                '<div class="kbf-ed-area" contenteditable="true" role="textbox" aria-multiline="true" aria-label="' + escapeHtml(opts.label || 'Message') + '" data-area></div>' +
            '</div>';

        var area = host.querySelector('[data-area]');
        var linkRow = host.querySelector('[data-link-row]');
        var linkInput = linkRow.querySelector('input');
        var savedRange = null;

        try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* ignore */ }

        function changed() {
            updateState();
            if (typeof opts.onChange === 'function') opts.onChange();
        }

        function saveSelection() {
            var sel = window.getSelection();
            if (sel && sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer)) {
                savedRange = sel.getRangeAt(0).cloneRange();
            }
        }

        function restoreSelection() {
            area.focus();
            if (savedRange) {
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(savedRange);
            } else {
                // Put the caret at the end
                var range = document.createRange();
                range.selectNodeContents(area);
                range.collapse(false);
                var s = window.getSelection();
                s.removeAllRanges();
                s.addRange(range);
            }
        }

        function inBlock(tag) {
            var sel = window.getSelection();
            if (!sel || !sel.rangeCount) return false;
            var node = sel.getRangeAt(0).startContainer;
            while (node && node !== area) {
                if (node.nodeType === 1 && node.nodeName.toLowerCase() === tag) return true;
                node = node.parentNode;
            }
            return false;
        }

        function updateState() {
            var states = {
                bold: document.queryCommandState && document.queryCommandState('bold'),
                italic: document.queryCommandState && document.queryCommandState('italic'),
                heading: inBlock('h2') || inBlock('h3'),
                list: inBlock('ul')
            };
            host.querySelectorAll('[data-cmd]').forEach(function (btn) {
                var cmd = btn.getAttribute('data-cmd');
                if (cmd in states) btn.setAttribute('aria-pressed', states[cmd] ? 'true' : 'false');
            });
        }

        function run(cmd) {
            restoreSelection();
            if (cmd === 'bold') document.execCommand('bold');
            else if (cmd === 'italic') document.execCommand('italic');
            else if (cmd === 'heading') document.execCommand('formatBlock', false, inBlock('h2') ? '<p>' : '<h2>');
            else if (cmd === 'list') document.execCommand('insertUnorderedList');
            saveSelection();
            changed();
        }

        // Keep the selection when the toolbar is pressed
        host.querySelector('.kbf-ed-bar').addEventListener('mousedown', function (e) {
            if (e.target.closest('[data-cmd]')) e.preventDefault();
        });

        host.querySelectorAll('[data-cmd]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cmd = btn.getAttribute('data-cmd');
                if (cmd === 'link') {
                    saveSelection();
                    linkRow.classList.add('kbf-open');
                    linkInput.value = '';
                    linkInput.focus();
                    return;
                }
                run(cmd);
            });
        });

        function closeLink() {
            linkRow.classList.remove('kbf-open');
        }

        function addLink() {
            var url = linkInput.value.trim();
            if (!url) { closeLink(); return; }
            if (!/^(https?:\/\/|mailto:|\{[a-z_]+\})/i.test(url)) {
                url = /@/.test(url) && !/\//.test(url) ? 'mailto:' + url : 'https://' + url;
            }
            restoreSelection();
            var sel = window.getSelection();
            if (sel && sel.rangeCount && !sel.isCollapsed) {
                document.execCommand('createLink', false, url);
            } else {
                document.execCommand('insertHTML', false, '<a href="' + escapeHtml(url) + '">' + escapeHtml(url) + '</a>&nbsp;');
            }
            closeLink();
            saveSelection();
            changed();
        }

        linkRow.querySelector('[data-link-ok]').addEventListener('click', addLink);
        linkRow.querySelector('[data-link-cancel]').addEventListener('click', function () { closeLink(); restoreSelection(); });
        linkInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addLink(); }
            if (e.key === 'Escape') { e.preventDefault(); closeLink(); restoreSelection(); }
        });

        var select = host.querySelector('[data-placeholder-select]');
        if (select) {
            select.addEventListener('mousedown', saveSelection);
            select.addEventListener('focus', saveSelection);
            select.addEventListener('change', function () {
                if (!select.value) return;
                api.insertText('{' + select.value + '}');
                select.value = '';
            });
        }

        // Paste as plain text so formatting from other programs does not come along
        area.addEventListener('paste', function (e) {
            var text = (e.clipboardData || window.clipboardData).getData('text/plain');
            e.preventDefault();
            document.execCommand('insertText', false, text);
        });

        area.addEventListener('input', changed);
        area.addEventListener('keyup', function () { saveSelection(); updateState(); });
        area.addEventListener('mouseup', function () { saveSelection(); updateState(); });
        area.addEventListener('blur', saveSelection);

        // Keyboard shortcuts for bold and italic work natively (Ctrl+B, Ctrl+I)

        var api = {
            getHtml: function () {
                var html = area.innerHTML.trim();
                return (html === '<br>' || html === '<p><br></p>') ? '' : html;
            },
            setHtml: function (html) {
                area.innerHTML = html || '';
                savedRange = null;
                updateState();
            },
            insertText: function (text) {
                restoreSelection();
                document.execCommand('insertText', false, text);
                saveSelection();
                changed();
            },
            focus: function () { area.focus(); },
            element: area
        };
        return api;
    }

    window.KBFEditor = { create: create };
})();
