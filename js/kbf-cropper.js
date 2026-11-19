/* ============================================
 * KBF-UK PHOTO CROP STEP
 *
 * A small canvas cropper written for this site (no library, nothing loaded
 * from elsewhere). When a member photo is chosen it is shown in a dialog
 * with a square frame: drag the photo (mouse, finger or arrow keys) to
 * place it and zoom with the slider, the mouse wheel, a pinch or the + and
 * - keys. "Use photo" crops the square in the browser and resizes it
 * (at least 600 x 600 px when the photo is big enough, at most 1200 x 1200)
 * to a JPEG file, which the page then uploads through its usual endpoint.
 * The server still checks and re-encodes every upload.
 *
 * Load after /js/escape.js and /js/kbf-dialog.js:
 *   <script src="/js/kbf-cropper.js"></script>
 *
 *   KBFCropper.open({ file: aFile })            // a chosen File / Blob
 *   KBFCropper.open({ src: ['/uploads/...'] })  // urls tried in order
 *       .then(function (file) { if (file) upload(file); })   // null = Cancel
 *       .catch(function (err) { show(err.message); })        // could not open
 *   Options: title, opener (focus goes back there), name (for the caption).
 *   KBFCropper.supported() is false on browsers without canvas export;
 *   pages then upload the photo as chosen.
 *
 * The look comes from a small style block added here (no Tailwind classes).
 * ============================================ */
(function (window, document) {
    'use strict';

    var MIN_OUT = 600;      // smallest side of the result when the photo allows
    var MAX_OUT = 1200;     // largest side of the result
    var QUALITY = 0.9;      // JPEG quality
    var MAX_ZOOM = 6;       // zoom range: 1x (photo just covers the frame) to 6x
    var FRAME = 0.84;       // frame size as a share of the stage

    var CSS =
        '.kbf-crop{position:fixed;inset:0;z-index:85;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.75);overflow-y:auto}' +
        '.kbf-crop-box{width:100%;max-width:26rem;background:#0f172a;border:4px solid #374151;padding:1.25rem;display:flex;flex-direction:column;gap:.9rem;margin:auto}' +
        '.kbf-crop-title{margin:0;color:#fff;font-weight:900;text-transform:uppercase;font-size:1.125rem}' +
        '.kbf-crop-hint{margin:0;color:#d1d5db;font-weight:600;font-size:.8rem;line-height:1.4}' +
        '.kbf-crop-stage{position:relative;width:100%;max-width:22rem;margin:0 auto;aspect-ratio:1/1;background:#020617;touch-action:none;cursor:grab;user-select:none;-webkit-user-select:none}' +
        '.kbf-crop-stage.is-dragging{cursor:grabbing}' +
        '.kbf-crop-stage:focus-visible{outline:3px solid var(--theme-primary,#fff);outline-offset:3px}' +
        '.kbf-crop-stage canvas{display:block;width:100%;height:100%}' +
        '.kbf-crop-msg{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-weight:900;font-size:.8rem;text-transform:uppercase}' +
        '.kbf-crop-zoom{display:flex;align-items:center;gap:.75rem}' +
        '.kbf-crop-zoom label{color:var(--theme-primary,#fff);font-weight:900;font-size:.7rem;text-transform:uppercase}' +
        '.kbf-crop-zoom input{flex:1;min-width:0;min-height:44px;accent-color:var(--theme-primary,#fff)}' +
        '.kbf-crop-row{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.5rem}' +
        '.kbf-crop-btn{min-height:44px;padding:.5rem 1rem;font-weight:900;font-size:.75rem;text-transform:uppercase;border:2px solid #374151;background:#1e293b;color:#e5e7eb;cursor:pointer}' +
        '.kbf-crop-btn:hover{border-color:#6b7280}' +
        '.kbf-crop-btn:focus-visible{outline:3px solid var(--theme-primary,#fff);outline-offset:2px}' +
        '.kbf-crop-btn:disabled{opacity:.5;cursor:not-allowed}' +
        '.kbf-crop-btn.is-main{background:var(--theme-primary,#fff);border-color:var(--theme-primary,#fff);color:#0F172A}';

    function addStyle() {
        if (document.getElementById('kbf-cropper-style')) return;
        var style = document.createElement('style');
        style.id = 'kbf-cropper-style';
        style.textContent = CSS;
        (document.head || document.documentElement).appendChild(style);
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function supported() {
        var c = document.createElement('canvas');
        return !!(c.getContext && c.getContext('2d') && (c.toBlob || c.toDataURL) && window.Promise);
    }

    function cleanUrl(url) {
        if (typeof url !== 'string' || url === '') return '';
        var KBF = window.KBF;
        return KBF && KBF.safeUrl ? KBF.safeUrl(url) : (/^\/[^\/\\]/.test(url) ? url : '');
    }

    // Load the first address that works into an <img>
    function loadImage(sources) {
        return new Promise(function (resolve, reject) {
            var i = 0;
            function next() {
                if (i >= sources.length) { reject(new Error('This photo could not be opened. Please choose another one.')); return; }
                var img = new Image();
                img.decoding = 'async';
                img.onload = function () {
                    if (img.naturalWidth > 0 && img.naturalHeight > 0) resolve(img); else { i++; next(); }
                };
                img.onerror = function () { i++; next(); };
                img.src = sources[i];
            }
            next();
        });
    }

    function canvasToFile(canvas) {
        return new Promise(function (resolve, reject) {
            function done(blob) {
                if (!blob) { reject(new Error('The photo could not be prepared. Please try again.')); return; }
                var file = blob;
                try { file = new File([blob], 'photo.jpg', { type: 'image/jpeg' }); } catch (e) { /* older browsers: the Blob works too */ }
                resolve(file);
            }
            if (canvas.toBlob) {
                canvas.toBlob(done, 'image/jpeg', QUALITY);
                return;
            }
            try {
                var data = canvas.toDataURL('image/jpeg', QUALITY);
                var bin = window.atob(data.split(',')[1]);
                var bytes = new Uint8Array(bin.length);
                for (var k = 0; k < bin.length; k++) bytes[k] = bin.charCodeAt(k);
                done(new Blob([bytes], { type: 'image/jpeg' }));
            } catch (e) {
                done(null);
            }
        });
    }

    function open(options) {
        var o = options || {};
        addStyle();
        var objectUrl = null;
        var sources = [];
        if (o.file) {
            objectUrl = URL.createObjectURL(o.file);
            sources.push(objectUrl);
        } else {
            (Array.isArray(o.src) ? o.src : [o.src]).forEach(function (s) {
                var u = cleanUrl(s);
                if (u && sources.indexOf(u) === -1) sources.push(u);
            });
        }
        if (!sources.length) return Promise.reject(new Error('There is no photo to adjust.'));

        return new Promise(function (resolve, reject) {
            // ---------- markup ----------
            var wrap = el('div', 'kbf-crop');
            wrap.setAttribute('role', 'dialog');
            wrap.setAttribute('aria-modal', 'true');
            var box = el('div', 'kbf-crop-box');
            var title = el('h2', 'kbf-crop-title', o.title || 'Adjust photo');
            title.id = 'kbf-crop-title-' + Date.now();
            wrap.setAttribute('aria-labelledby', title.id);
            var hint = el('p', 'kbf-crop-hint',
                'Move the photo so the face sits inside the square. Drag it, or use the arrow keys. ' +
                'Zoom with the slider, the mouse wheel, a pinch, or the + and - keys.');
            hint.id = title.id + '-hint';
            wrap.setAttribute('aria-describedby', hint.id);

            var stage = el('div', 'kbf-crop-stage');
            stage.tabIndex = 0;
            stage.setAttribute('role', 'group');
            stage.setAttribute('aria-label', 'Photo position. Arrow keys move the photo, plus and minus zoom.');
            var canvas = el('canvas');
            canvas.setAttribute('aria-hidden', 'true');
            var msg = el('div', 'kbf-crop-msg', 'Loading...');
            stage.appendChild(canvas);
            stage.appendChild(msg);

            var zoomRow = el('div', 'kbf-crop-zoom');
            var zoomLabel = el('label', '', 'Zoom');
            var zoom = el('input');
            zoom.type = 'range';
            zoom.min = '0';
            zoom.max = '1000';
            zoom.step = '1';
            zoom.value = '0';
            zoom.id = title.id + '-zoom';
            zoomLabel.htmlFor = zoom.id;
            zoom.disabled = true;
            zoomRow.appendChild(zoomLabel);
            zoomRow.appendChild(zoom);

            var row = el('div', 'kbf-crop-row');
            var cancelBtn = el('button', 'kbf-crop-btn', 'Cancel');
            cancelBtn.type = 'button';
            var useBtn = el('button', 'kbf-crop-btn is-main', 'Use photo');
            useBtn.type = 'button';
            useBtn.disabled = true;
            row.appendChild(cancelBtn);
            row.appendChild(useBtn);

            box.appendChild(title);
            box.appendChild(hint);
            box.appendChild(stage);
            box.appendChild(zoomRow);
            box.appendChild(row);
            wrap.appendChild(box);

            // ---------- state (image pixels) ----------
            var img = null;
            var W = 0, H = 0;          // photo size
            var cx = 0, cy = 0;        // photo point at the centre of the frame
            var z = 1;                 // zoom: 1 = the photo just covers the frame
            var size = 0;              // stage size in CSS px
            var dpr = 1;
            var ctx = canvas.getContext('2d');
            var finished = false;
            var oldOverflow = document.documentElement.style.overflow;

            function frameSize() { return size * FRAME; }
            function minScale() { return frameSize() / Math.min(W, H); }
            function scale() { return minScale() * z; }

            function clamp() {
                var half = frameSize() / 2 / scale();
                cx = Math.min(Math.max(cx, half), W - half);
                cy = Math.min(Math.max(cy, half), H - half);
            }

            function measure() {
                var rect = stage.getBoundingClientRect();
                size = Math.max(1, Math.round(rect.width));
                stage.style.height = size + 'px';   // browsers without aspect-ratio
                dpr = Math.min(window.devicePixelRatio || 1, 3);
                canvas.width = Math.round(size * dpr);
                canvas.height = Math.round(size * dpr);
            }

            function draw() {
                if (!img || !size) return;
                clamp();
                var s = scale();
                ctx.setTransform(1, 0, 0, 1, 0, 0);
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.imageSmoothingEnabled = true;
                if ('imageSmoothingQuality' in ctx) ctx.imageSmoothingQuality = 'high';
                ctx.setTransform(dpr * s, 0, 0, dpr * s, dpr * (size / 2 - cx * s), dpr * (size / 2 - cy * s));
                ctx.drawImage(img, 0, 0);
                // Shade outside the square, then the frame and a round guide
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                var f = frameSize();
                var m = (size - f) / 2;
                ctx.fillStyle = 'rgba(2, 6, 23, 0.62)';
                ctx.beginPath();
                ctx.rect(0, 0, size, size);
                ctx.rect(m, m, f, f);
                ctx.fill('evenodd');
                ctx.lineWidth = 2;
                ctx.strokeStyle = '#ffffff';
                ctx.strokeRect(m, m, f, f);
                if (ctx.setLineDash) ctx.setLineDash([6, 6]);
                ctx.strokeStyle = 'rgba(255, 255, 255, 0.55)';
                ctx.beginPath();
                ctx.arc(size / 2, size / 2, f / 2, 0, Math.PI * 2);
                ctx.stroke();
                if (ctx.setLineDash) ctx.setLineDash([]);
            }

            function setZoom(next, keepSlider) {
                z = Math.min(Math.max(next, 1), MAX_ZOOM);
                if (!keepSlider) zoom.value = String(Math.round(Math.log(z) / Math.log(MAX_ZOOM) * 1000));
                zoom.setAttribute('aria-valuetext', Math.round(z * 100) + '%');
                draw();
            }

            function move(dxCss, dyCss) {
                var s = scale();
                cx -= dxCss / s;
                cy -= dyCss / s;
                draw();
            }

            // ---------- pointer: drag and pinch ----------
            var pointers = {};
            var pinchDist = 0;
            function pointerList() { return Object.keys(pointers).map(function (k) { return pointers[k]; }); }
            function distance(a, b) { return Math.sqrt(Math.pow(a.x - b.x, 2) + Math.pow(a.y - b.y, 2)); }

            stage.addEventListener('pointerdown', function (e) {
                if (!img) return;
                if (e.pointerType === 'mouse' && e.button !== 0) return;
                pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
                try { stage.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
                stage.classList.add('is-dragging');
                var list = pointerList();
                if (list.length === 2) pinchDist = distance(list[0], list[1]);
                e.preventDefault();
                stage.focus();
            });
            stage.addEventListener('pointermove', function (e) {
                var p = pointers[e.pointerId];
                if (!p) return;
                var list = pointerList();
                if (list.length >= 2) {
                    p.x = e.clientX;
                    p.y = e.clientY;
                    list = pointerList();
                    var d = distance(list[0], list[1]);
                    if (pinchDist > 0 && d > 0) setZoom(z * d / pinchDist);
                    pinchDist = d;
                } else {
                    var dx = e.clientX - p.x;
                    var dy = e.clientY - p.y;
                    p.x = e.clientX;
                    p.y = e.clientY;
                    move(dx, dy);
                }
            });
            function pointerEnd(e) {
                delete pointers[e.pointerId];
                pinchDist = 0;
                var list = pointerList();
                if (list.length === 2) pinchDist = distance(list[0], list[1]);
                if (!list.length) stage.classList.remove('is-dragging');
            }
            stage.addEventListener('pointerup', pointerEnd);
            stage.addEventListener('pointercancel', pointerEnd);
            stage.addEventListener('wheel', function (e) {
                if (!img) return;
                e.preventDefault();
                setZoom(z * Math.exp(-e.deltaY * 0.0015));
            }, { passive: false });

            // ---------- keyboard ----------
            stage.addEventListener('keydown', function (e) {
                if (!img) return;
                var step = e.shiftKey ? 40 : 10;
                var handled = true;
                switch (e.key) {
                    // The photo moves the way the arrow points
                    case 'ArrowLeft': case 'Left': move(-step, 0); break;
                    case 'ArrowRight': case 'Right': move(step, 0); break;
                    case 'ArrowUp': case 'Up': move(0, -step); break;
                    case 'ArrowDown': case 'Down': move(0, step); break;
                    case '+': case '=': setZoom(z * 1.1); break;
                    case '-': case '_': setZoom(z / 1.1); break;
                    default: handled = false;
                }
                if (handled) e.preventDefault();
            });

            zoom.addEventListener('input', function () {
                setZoom(Math.pow(MAX_ZOOM, Number(zoom.value) / 1000), true);
            });

            function onResize() { measure(); draw(); }
            window.addEventListener('resize', onResize);

            // ---------- finish ----------
            function finish(result, error) {
                if (finished) return;
                finished = true;
                window.removeEventListener('resize', onResize);
                document.documentElement.style.overflow = oldOverflow;
                if (window.KBFDialog) window.KBFDialog.close(wrap);
                if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
                if (objectUrl) { try { URL.revokeObjectURL(objectUrl); } catch (e) { /* ignore */ } }
                if (!window.KBFDialog && o.opener && o.opener.focus && o.opener.isConnected) {
                    try { o.opener.focus(); } catch (e) { /* ignore */ }
                }
                if (error) reject(error); else resolve(result);
            }

            cancelBtn.addEventListener('click', function () { finish(null); });

            useBtn.addEventListener('click', function () {
                if (!img) return;
                clamp();
                var side = frameSize() / scale();               // the square in photo pixels
                var sx = Math.min(Math.max(cx - side / 2, 0), W - side);
                var sy = Math.min(Math.max(cy - side / 2, 0), H - side);
                var out = Math.round(Math.min(MAX_OUT, Math.max(side, Math.min(MIN_OUT, Math.min(W, H)))));
                var c = document.createElement('canvas');
                c.width = out;
                c.height = out;
                var octx = c.getContext('2d');
                octx.fillStyle = '#ffffff';                     // see-through PNG areas become white
                octx.fillRect(0, 0, out, out);
                octx.imageSmoothingEnabled = true;
                if ('imageSmoothingQuality' in octx) octx.imageSmoothingQuality = 'high';
                useBtn.disabled = true;
                useBtn.textContent = 'Preparing...';
                try {
                    octx.drawImage(img, sx, sy, side, side, 0, 0, out, out);
                } catch (e) {
                    finish(null, new Error('The photo could not be prepared. Please try again.'));
                    return;
                }
                canvasToFile(c).then(function (file) { finish(file); }, function (err) { finish(null, err); });
            });

            document.body.appendChild(wrap);
            document.documentElement.style.overflow = 'hidden';
            measure();
            var dialogOpts = { onClose: function () { finish(null); }, initialFocus: stage, opener: o.opener || null, labelledBy: title.id };
            if (window.KBFDialog) {
                window.KBFDialog.open(wrap, dialogOpts);
            } else {
                window.setTimeout(function () { stage.focus(); }, 0);
                wrap.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); finish(null); }
                });
            }

            loadImage(sources).then(function (loaded) {
                if (finished) return;
                img = loaded;
                W = img.naturalWidth;
                H = img.naturalHeight;
                msg.parentNode.removeChild(msg);
                measure();
                // Start like the round photos on the site: centred across,
                // the top part of a tall portrait (object-position center 20%)
                var half = frameSize() / 2 / minScale();
                cx = W / 2;
                cy = half + (H - 2 * half) * 0.2;
                setZoom(1);
                zoom.disabled = false;
                useBtn.disabled = false;
            }, function (err) {
                finish(null, err);
            });
        });
    }

    window.KBFCropper = { open: open, supported: supported };
})(window, document);
