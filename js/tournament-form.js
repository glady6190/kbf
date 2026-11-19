/* ============================================
 * KBF tournament form (create + edit details)
 * Shared by clubadmin/create-tournament.html and admin/tournaments.html.
 *
 *   const tf = KBFTournamentForm.mount(hostElement, { showHowItWorks: true, canEditOrganiser: false });
 *   tf.loadForEdit(tournament)    switch to "edit details" mode (sections 3-5 + start time)
 *   tf.organiserChange()          edit mode only: the new organiser name when it was
 *                                 changed (canEditOrganiser), otherwise null
 *   tf.coreChanges()              edit mode with canEditCore (tournaments.manage_all):
 *                                 the changed name, tournamentDate, venue, location,
 *                                 postCode and categoriesOffered for PUT, or null
 *
 * The required "Organiser" field (free text) is pre-filled on a new form from
 * GET /api/tournaments.php?lastOrganiser=1 and suggests existing names (datalist)
 * from GET /api/tournaments.php?organiserNames=1. In edit mode it is locked unless
 * canEditOrganiser is true (tournaments.manage_all); updateDetails never sends
 * it, so the caller saves a change with PUT /api/tournaments.php.
 *   tf.validate()                 returns an error message or null
 *   tf.buildFormData()            FormData for POST /api/tournaments.php
 *   tf.reset()                    back to an empty create form
 *
 * Every value from the server is written with .value / .textContent, never as HTML.
 * ============================================ */
(function () {
    'use strict';

    // Poster size limit from Site settings (4 MB when the shell has no value)
    function posterMaxMb() {
        return (window.KBFShell && typeof window.KBFShell.uploadLimitMb === 'function')
            ? window.KBFShell.uploadLimitMb('poster', 4) : 4;
    }
    var POSTER_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    var DESCRIPTION_MAX = 2000;
    var CATEGORIES_MAX = 100;
    var ORGANISER_MAX = 150;

    function injectStyles() {
        if (document.getElementById('tf-styles')) return;
        var style = document.createElement('style');
        style.id = 'tf-styles';
        style.textContent =
            '.tf-input{width:100%;background:#1e293b;border:2px solid #374151;color:#fff;padding:.625rem .875rem;font-weight:700;font-size:.875rem;transition:border-color .2s ease;border-radius:0;}' +
            '.tf-input::placeholder{color:#6b7280;}' +
            '.tf-input:focus{outline:none;border-color:var(--theme-primary);}' +
            '.tf-input:disabled{opacity:.55;cursor:not-allowed;}' +
            '.tf-step{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;flex-shrink:0;background:var(--theme-primary);color:#0F172A;font-weight:900;font-size:.875rem;}' +
            '.tf-label{display:block;font-size:.75rem;font-weight:900;text-transform:uppercase;color:var(--theme-primary);margin-bottom:.5rem;}' +
            '.tf-optional{color:#9ca3af;font-weight:700;text-transform:none;}' +
            '.tf-hint{font-size:.75rem;color:#9ca3af;font-weight:600;margin-top:.375rem;}' +
            '.tf-btn-theme{background:var(--theme-primary);color:#0F172A;}' +
            '.tf-poster-frame{background:#0f172a;border:2px dashed #374151;min-height:12rem;display:flex;align-items:center;justify-content:center;padding:.75rem;}' +
            '.tf-poster-frame img{display:block;max-width:100%;max-height:28rem;width:auto;height:auto;object-fit:contain;}' +
            '@media (min-width:640px){.tf-input{border-width:4px;}.tf-poster-frame{border-width:4px;}}';
        document.head.appendChild(style);
    }

    function optional() {
        return ' <span class="tf-optional">(optional)</span>';
    }

    function sectionHeader(number, title) {
        return '<div class="flex items-center gap-3 mb-4 sm:mb-5">' +
                   '<span class="tf-step">' + number + '</span>' +
                   '<h2 class="text-base sm:text-lg font-black uppercase text-white">' + title + '</h2>' +
               '</div>';
    }

    function markup(opts) {
        var how = opts.showHowItWorks ?
            '<div class="tf-how border-2 sm:border-4 border-gray-700 bg-slate-900 p-4 sm:p-5">' +
                '<div class="flex items-center gap-2 mb-3">' +
                    '<svg class="w-5 h-5 flex-shrink-0" style="color:var(--theme-primary)" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">' +
                        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>' +
                    '</svg>' +
                    '<h2 class="text-sm font-black uppercase text-white">How this works</h2>' +
                '</div>' +
                '<ol class="list-decimal pl-5 space-y-1 text-sm text-gray-300 font-semibold">' +
                    '<li>Fill in the details; only fields marked * are required.</li>' +
                    '<li>KBF checks and approves the tournament.</li>' +
                    '<li>It appears on the Tournaments page and in the ticker on the home page.</li>' +
                    '<li>You can come back and add or change the poster and registration link at any time.</li>' +
                '</ol>' +
            '</div>' : '';

        var organiserField =
            '<div>' +
                '<label class="tf-label" for="tf-organiser">Organiser *</label>' +
                '<input type="text" id="tf-organiser" name="organiserName" required maxlength="' + ORGANISER_MAX + '" class="tf-input" autocomplete="off" list="tf-organiser-list" aria-describedby="tf-organiser-hint">' +
                '<datalist id="tf-organiser-list"></datalist>' +
                '<p class="tf-hint" id="tf-organiser-hint">The organiser running this tournament, as players should see it. Pick an existing name if your organiser has run tournaments before, so they are grouped together.</p>' +
            '</div>';

        return '' +
        '<div class="space-y-5 sm:space-y-6">' +
            how +
            '<div id="tf-edit-note" class="hidden border-2 sm:border-4 border-gray-700 bg-slate-800 p-3 sm:p-4 text-sm text-gray-300 font-semibold">' +
                'You are editing the extra details. The name, date, venue and categories are shown for reference and cannot be changed here. Saving does not change the approval status.' +
            '</div>' +

            // 1. Tournament details
            '<section class="border-2 sm:border-4 border-gray-700 bg-slate-900 p-4 sm:p-6">' +
                sectionHeader(1, 'Tournament details') +
                '<div class="space-y-4">' +
                    organiserField +
                    '<div>' +
                        '<label class="tf-label" for="tf-name">Tournament name *</label>' +
                        '<input type="text" id="tf-name" name="name" required minlength="3" maxlength="200" class="tf-input tf-core uppercase" placeholder="MANCHESTER SPRING CHAMPIONSHIP">' +
                    '</div>' +
                    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' +
                        '<div>' +
                            '<label class="tf-label" for="tf-date">Date *</label>' +
                            '<input type="date" id="tf-date" name="tournamentDate" required class="tf-input tf-core">' +
                        '</div>' +
                        '<div>' +
                            '<label class="tf-label" for="tf-start">Start time' + optional() + '</label>' +
                            '<input type="time" id="tf-start" name="startTime" class="tf-input">' +
                        '</div>' +
                    '</div>' +
                    '<div>' +
                        '<label class="tf-label" for="tf-categories">Categories offered *</label>' +
                        '<input type="text" id="tf-categories" name="categoriesOffered" required maxlength="' + CATEGORIES_MAX + '" class="tf-input tf-core uppercase" placeholder="A&amp;B, C&amp;C, C&amp;D, B&amp;D, C&amp;E">' +
                        '<p class="tf-hint">The categories or groups that will compete, for example "A&amp;B, C&amp;C, C&amp;D". <span id="tf-categories-count">0</span>/' + CATEGORIES_MAX + ' characters</p>' +
                    '</div>' +
                '</div>' +
            '</section>' +

            // 2. Venue
            '<section class="border-2 sm:border-4 border-gray-700 bg-slate-900 p-4 sm:p-6">' +
                sectionHeader(2, 'Venue') +
                '<div class="space-y-4">' +
                    '<div>' +
                        '<label class="tf-label" for="tf-venue">Venue name *</label>' +
                        '<input type="text" id="tf-venue" name="venue" required class="tf-input tf-core uppercase" placeholder="MANCHESTER SPORTS CENTRE">' +
                    '</div>' +
                    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' +
                        '<div>' +
                            '<label class="tf-label" for="tf-location">Town or city *</label>' +
                            '<input type="text" id="tf-location" name="location" required class="tf-input tf-core uppercase" placeholder="MANCHESTER">' +
                        '</div>' +
                        '<div>' +
                            '<label class="tf-label" for="tf-postcode">Postcode' + optional() + '</label>' +
                            '<input type="text" id="tf-postcode" name="postCode" class="tf-input tf-core uppercase" placeholder="M1 1AA">' +
                            '<p class="tf-hint">Used for the map link.</p>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</section>' +

            // 3. Registration
            '<section class="border-2 sm:border-4 border-gray-700 bg-slate-900 p-4 sm:p-6">' +
                sectionHeader(3, 'Registration') +
                '<div class="space-y-4">' +
                    '<div>' +
                        '<label class="tf-label" for="tf-reg-url">Registration form link' + optional() + '</label>' +
                        '<input type="url" id="tf-reg-url" name="registrationUrl" maxlength="500" class="tf-input" placeholder="https://forms.gle/..." inputmode="url" autocomplete="off">' +
                        '<p class="tf-hint">Paste your Google Form or other sign-up link. Players see a Register button that opens it. Leave it empty if you are not taking entries online.</p>' +
                    '</div>' +
                    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' +
                        '<div>' +
                            '<label class="tf-label" for="tf-reg-close">Registration closes' + optional() + '</label>' +
                            '<input type="date" id="tf-reg-close" name="registrationCloseDate" class="tf-input">' +
                            '<p class="tf-hint">The Register button disappears after this date.</p>' +
                        '</div>' +
                        '<div>' +
                            '<label class="tf-label" for="tf-fee">Entry fee' + optional() + '</label>' +
                            '<input type="text" id="tf-fee" name="entryFee" maxlength="100" class="tf-input" placeholder="&#163;20 per pair">' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</section>' +

            // 4. Poster
            '<section class="border-2 sm:border-4 border-gray-700 bg-slate-900 p-4 sm:p-6">' +
                sectionHeader(4, 'Poster <span class="tf-optional">(optional)</span>') +
                '<div class="space-y-3">' +
                    '<div class="tf-poster-frame" id="tf-poster-frame">' +
                        '<img id="tf-poster-img" alt="Poster preview" class="hidden">' +
                        '<div id="tf-poster-empty" class="text-center text-gray-500">' +
                            '<svg class="w-12 h-12 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">' +
                                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>' +
                            '</svg>' +
                            '<p class="text-xs font-bold uppercase">No poster chosen</p>' +
                        '</div>' +
                    '</div>' +
                    '<p id="tf-poster-caption" class="tf-hint hidden"></p>' +
                    '<div class="flex flex-col sm:flex-row gap-2">' +
                        '<label for="tf-poster-input" class="tf-btn-theme cursor-pointer inline-flex items-center justify-center gap-2 px-4 py-2 font-black uppercase text-xs sm:text-sm border-2 border-gray-700 hover:opacity-90 transition">' +
                            '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">' +
                                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>' +
                            '</svg>' +
                            '<span id="tf-poster-choose-text">Choose poster</span>' +
                        '</label>' +
                        '<input type="file" id="tf-poster-input" accept="image/jpeg,image/png,image/webp" class="sr-only">' +
                        '<button type="button" id="tf-poster-remove" class="hidden inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-800 border-2 border-gray-700 text-gray-200 font-black uppercase text-xs sm:text-sm hover:border-red-500 hover:text-red-400 transition">' +
                            '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">' +
                                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>' +
                            '</svg>' +
                            'Remove' +
                        '</button>' +
                    '</div>' +
                    '<p id="tf-poster-error" class="hidden text-xs font-bold text-red-400"></p>' +
                    '<p class="tf-hint">JPG, PNG or WebP, up to ' + posterMaxMb() + ' MB. Portrait and landscape posters both work. Without a poster, the tournament shows a date card instead.</p>' +
                '</div>' +
            '</section>' +

            // 5. More information
            '<section class="border-2 sm:border-4 border-gray-700 bg-slate-900 p-4 sm:p-6">' +
                sectionHeader(5, 'More information') +
                '<div class="space-y-4">' +
                    '<div>' +
                        '<label class="tf-label" for="tf-description">About this tournament' + optional() + '</label>' +
                        '<textarea id="tf-description" name="description" rows="6" maxlength="' + DESCRIPTION_MAX + '" class="tf-input font-semibold" placeholder="Format, prizes, parking, what to bring..."></textarea>' +
                        '<p class="tf-hint"><span id="tf-description-count">0</span>/2,000 characters</p>' +
                    '</div>' +
                    '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' +
                        '<div>' +
                            '<label class="tf-label" for="tf-contact-name">Contact name' + optional() + '</label>' +
                            '<input type="text" id="tf-contact-name" name="contactName" maxlength="100" class="tf-input" autocomplete="off">' +
                        '</div>' +
                        '<div>' +
                            '<label class="tf-label" for="tf-contact-phone">Contact phone or WhatsApp' + optional() + '</label>' +
                            '<input type="tel" id="tf-contact-phone" name="contactPhone" maxlength="40" class="tf-input" placeholder="+44 7700 900123" autocomplete="off">' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</section>' +
        '</div>';
    }

    // Only images served from this site's uploads folder are previewed.
    function safeImagePath(path) {
        if (typeof path !== 'string') return '';
        if (!/^\/uploads\/[A-Za-z0-9\/_.\-]+$/.test(path) || path.indexOf('..') !== -1) return '';
        return path;
    }

    function textLength(value) {
        return Array.from(value || '').length;
    }

    function mount(host, options) {
        var opts = Object.assign({ showHowItWorks: false, canEditOrganiser: false, canEditCore: false }, options || {});
        injectStyles();
        host.innerHTML = markup(opts);

        function $(id) { return host.querySelector('#' + id); }

        var el = {
            organiser: $('tf-organiser'),
            name: $('tf-name'),
            date: $('tf-date'),
            start: $('tf-start'),
            categories: $('tf-categories'),
            categoriesCount: $('tf-categories-count'),
            venue: $('tf-venue'),
            location: $('tf-location'),
            postcode: $('tf-postcode'),
            regUrl: $('tf-reg-url'),
            regClose: $('tf-reg-close'),
            fee: $('tf-fee'),
            description: $('tf-description'),
            descriptionCount: $('tf-description-count'),
            contactName: $('tf-contact-name'),
            contactPhone: $('tf-contact-phone'),
            posterInput: $('tf-poster-input'),
            posterImg: $('tf-poster-img'),
            posterEmpty: $('tf-poster-empty'),
            posterCaption: $('tf-poster-caption'),
            posterRemove: $('tf-poster-remove'),
            posterError: $('tf-poster-error'),
            posterChooseText: $('tf-poster-choose-text'),
            editNote: $('tf-edit-note')
        };

        var state = {
            mode: 'create',
            tournament: null,
            file: null,
            objectUrl: null,
            ownPosterUrl: '',   // the tournament's own saved poster
            postPosterUrl: '',  // image borrowed from the linked post
            removeOwn: false
        };

        // ---- counters ----
        function updateCounters() {
            var catLen = textLength(el.categories.value);
            el.categoriesCount.textContent = catLen;
            el.categoriesCount.classList.toggle('text-red-400', catLen >= CATEGORIES_MAX);

            var descLen = textLength(el.description.value);
            el.descriptionCount.textContent = descLen.toLocaleString('en-GB');
            el.descriptionCount.classList.toggle('text-red-400', descLen >= DESCRIPTION_MAX);
        }
        el.categories.addEventListener('input', updateCounters);
        el.description.addEventListener('input', updateCounters);

        // ---- poster ----
        function showPosterError(message) {
            el.posterError.textContent = message || '';
            el.posterError.classList.toggle('hidden', !message);
        }

        function clearSelectedFile() {
            if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
            state.objectUrl = null;
            state.file = null;
            el.posterInput.value = '';
        }

        function renderPoster() {
            var src = '';
            var caption = '';
            var canRemove = false;

            if (state.file) {
                src = state.objectUrl;
                caption = 'New poster selected: ' + state.file.name + '. It is saved when you submit the form.';
                canRemove = true;
            } else if (state.ownPosterUrl && !state.removeOwn) {
                src = state.ownPosterUrl;
                caption = 'Current poster.';
                canRemove = true;
            } else if (state.postPosterUrl) {
                src = state.postPosterUrl;
                caption = state.removeOwn
                    ? 'The poster will be removed when you save. The image from the linked post will show instead.'
                    : 'Showing the image from the linked post. Choose a poster to use your own instead.';
            } else if (state.removeOwn) {
                caption = 'The poster will be removed when you save.';
            }

            if (src) {
                if (window.KBF && window.KBF.setImageVariant) window.KBF.setImageVariant(el.posterImg, src, 'display');
                else el.posterImg.src = src;
                el.posterImg.classList.remove('hidden');
                el.posterEmpty.classList.add('hidden');
            } else {
                el.posterImg.removeAttribute('src');
                el.posterImg.classList.add('hidden');
                el.posterEmpty.classList.remove('hidden');
            }

            el.posterCaption.textContent = caption;
            el.posterCaption.classList.toggle('hidden', !caption);
            el.posterRemove.classList.toggle('hidden', !canRemove);
            el.posterChooseText.textContent = (src && (state.file || (state.ownPosterUrl && !state.removeOwn))) ? 'Change poster' : 'Choose poster';
        }

        el.posterInput.addEventListener('change', function () {
            showPosterError('');
            var file = el.posterInput.files && el.posterInput.files[0];
            if (!file) return;

            if (POSTER_TYPES.indexOf(file.type) === -1) {
                el.posterInput.value = '';
                showPosterError('Please choose a JPG, PNG or WebP image.');
                return;
            }
            if (file.size > posterMaxMb() * 1024 * 1024) {
                el.posterInput.value = '';
                showPosterError('The poster must be ' + posterMaxMb() + ' MB or smaller.');
                return;
            }

            if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
            state.file = file;
            state.objectUrl = URL.createObjectURL(file);
            state.removeOwn = false;
            renderPoster();
        });

        el.posterRemove.addEventListener('click', function () {
            showPosterError('');
            if (state.file) {
                clearSelectedFile();
            } else if (state.ownPosterUrl) {
                state.removeOwn = true;
            }
            renderPoster();
        });

        // ---- modes ----
        var coreFields = Array.prototype.slice.call(host.querySelectorAll('.tf-core'));

        function setCoreDisabled(disabled) {
            // canEditCore: name, date, venue and categories stay editable when editing
            coreFields.forEach(function (input) { input.disabled = disabled && !opts.canEditCore; });
            // Organiser: locked when editing unless the user may change it
            el.organiser.disabled = disabled && !opts.canEditOrganiser;
        }

        // Pre-fill a new form with the organiser name this user used last
        var lastOrganiserPromise = null;
        function prefillOrganiser() {
            if (!lastOrganiserPromise) {
                lastOrganiserPromise = fetch('/api/tournaments.php?lastOrganiser=1', { credentials: 'include' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) { return (data && data.success && data.lastOrganiserName) ? String(data.lastOrganiserName) : ''; })
                    .catch(function () { return ''; });
            }
            lastOrganiserPromise.then(function (name) {
                if (state.mode === 'create' && !el.organiser.value && name) {
                    el.organiser.value = name;
                }
            });
        }

        // Suggestions for the Organiser field (datalist), most used first
        var organiserNames = [];
        function organiserKey(value) {
            return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }
        fetch('/api/tournaments.php?organiserNames=1', { credentials: 'include' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success || !Array.isArray(data.organiserNames)) return;
                organiserNames = data.organiserNames.map(String);
                var list = $('tf-organiser-list');
                organiserNames.forEach(function (name) {
                    var option = document.createElement('option');
                    option.value = name;
                    list.appendChild(option);
                });
            })
            .catch(function () { /* suggestions are optional */ });

        // Tidy the name: trim, collapse spaces, and use the existing spelling
        // when it matches an existing name apart from upper and lower case
        function normaliseOrganiser() {
            var value = el.organiser.value.replace(/\s+/g, ' ').trim();
            var key = organiserKey(value);
            for (var i = 0; i < organiserNames.length; i++) {
                if (organiserKey(organiserNames[i]) === key) { value = organiserNames[i]; break; }
            }
            if (el.organiser.value !== value) el.organiser.value = value;
        }
        el.organiser.addEventListener('change', normaliseOrganiser);

        function reset() {
            state.mode = 'create';
            state.tournament = null;
            state.ownPosterUrl = '';
            state.postPosterUrl = '';
            state.removeOwn = false;
            clearSelectedFile();
            host.querySelectorAll('input:not([type=file]), textarea, select').forEach(function (input) { input.value = ''; });
            setCoreDisabled(false);
            el.editNote.classList.add('hidden');
            showPosterError('');
            updateCounters();
            renderPoster();
            prefillOrganiser();
        }

        function loadForEdit(t) {
            reset();
            state.mode = 'edit';
            state.tournament = t;

            el.organiser.value = t.OrganiserName || t.ClubName || '';
            state.originalOrganiser = el.organiser.value;
            el.name.value = t.Name || '';
            el.date.value = (t.TournamentDate || '').slice(0, 10);
            el.categories.value = t.CategoriesOffered || '';
            el.venue.value = t.Venue || '';
            el.location.value = t.Location || '';
            el.postcode.value = t.PostCode || '';

            el.start.value = (t.StartTime || '').slice(0, 5);
            el.regUrl.value = t.RegistrationUrl || '';
            el.regClose.value = (t.RegistrationCloseDate || '').slice(0, 10);
            el.fee.value = t.EntryFee || '';
            el.description.value = t.Description || '';
            el.contactName.value = t.ContactName || '';
            el.contactPhone.value = t.ContactPhone || '';

            if (t.PosterSource === 'tournament') {
                state.ownPosterUrl = safeImagePath(t.PosterUrl || t.PosterPath);
            } else if (t.PosterSource === 'post') {
                state.postPosterUrl = safeImagePath(t.PosterUrl);
            }

            state.originalCore = coreValues();
            setCoreDisabled(true);
            el.editNote.textContent = opts.canEditCore
                ? 'You are editing this tournament. Saving does not change the approval status.'
                : 'You are editing the extra details. The name, date, venue and categories are shown for reference and cannot be changed here. Saving does not change the approval status.';
            el.editNote.classList.remove('hidden');
            updateCounters();
            renderPoster();
        }

        function coreValues() {
            return {
                name: el.name.value.trim(),
                tournamentDate: el.date.value,
                venue: el.venue.value.trim(),
                location: el.location.value.trim(),
                postCode: el.postcode.value.trim(),
                categoriesOffered: el.categories.value.trim()
            };
        }

        // Edit mode with canEditCore: the changed core fields, or null
        function coreChanges() {
            if (state.mode !== 'edit' || !opts.canEditCore || !state.originalCore) return null;
            var now = coreValues();
            var changes = {};
            var any = false;
            Object.keys(now).forEach(function (key) {
                if (now[key] !== state.originalCore[key]) {
                    changes[key] = now[key];
                    any = true;
                }
            });
            return any ? changes : null;
        }

        // Edit mode: the new organiser name, or null when unchanged or locked
        function organiserChange() {
            if (state.mode !== 'edit' || !opts.canEditOrganiser) return null;
            var value = el.organiser.value.trim();
            return (value && value !== (state.originalOrganiser || '').trim()) ? value : null;
        }

        // ---- validation ----
        function validate() {
            var coreEditable = state.mode === 'create' || opts.canEditCore;
            var tournamentDate = !coreEditable
                ? ((state.tournament && state.tournament.TournamentDate) || '').slice(0, 10)
                : el.date.value;

            if (!el.organiser.disabled) normaliseOrganiser();
            var organiser = el.organiser.value.trim();
            if ((state.mode === 'create' || opts.canEditOrganiser) && !organiser) return 'Please enter the organiser.';
            if (textLength(organiser) > ORGANISER_MAX) return 'Organiser must be 150 characters or fewer.';

            if (coreEditable) {
                if (el.name.value.trim().length < 3) return 'Tournament name must be at least 3 characters.';
                if (!el.date.value) return 'Please choose the tournament date.';
                if (!el.categories.value.trim()) return 'Please enter the categories offered.';
                if (textLength(el.categories.value.trim()) > CATEGORIES_MAX) return 'Categories text is too long (max 100 characters).';
                if (!el.venue.value.trim()) return 'Please enter the venue name.';
                if (!el.location.value.trim()) return 'Please enter the town or city.';
            }

            var url = el.regUrl.value.trim();
            if (url) {
                if (url.length > 500) return 'The registration link must be 500 characters or fewer.';
                if (/^[a-z][a-z0-9+.\-]*:/i.test(url) && !/^https?:\/\//i.test(url) && !/^[^:]+:\d/.test(url)) {
                    return 'The registration link must start with http:// or https://.';
                }
            }

            var close = el.regClose.value;
            if (close && tournamentDate && close > tournamentDate) {
                return 'Registration cannot close after the tournament date.';
            }

            if (textLength(el.fee.value.trim()) > 100) return 'Entry fee must be 100 characters or fewer.';
            if (textLength(el.description.value.trim()) > DESCRIPTION_MAX) return 'About this tournament must be 2,000 characters or fewer.';
            if (textLength(el.contactName.value.trim()) > 100) return 'Contact name must be 100 characters or fewer.';

            var phone = el.contactPhone.value.trim();
            if (phone && (phone.length > 40 || !/^[0-9 +()\-]+$/.test(phone) || !/\d/.test(phone))) {
                return 'Contact phone may only contain digits, spaces, +, brackets and hyphens.';
            }
            return null;
        }

        // ---- payload ----
        function appendExtras(fd) {
            fd.append('startTime', el.start.value);
            fd.append('registrationUrl', el.regUrl.value.trim());
            fd.append('registrationCloseDate', el.regClose.value);
            fd.append('entryFee', el.fee.value.trim());
            fd.append('description', el.description.value);
            fd.append('contactName', el.contactName.value.trim());
            fd.append('contactPhone', el.contactPhone.value.trim());
        }

        function buildFormData() {
            var fd = new FormData();
            if (state.mode === 'edit') {
                fd.append('action', 'updateDetails');
                fd.append('tournamentId', String(state.tournament.TournamentId));
                appendExtras(fd);
                if (state.file) {
                    fd.append('poster', state.file);
                } else if (state.removeOwn) {
                    fd.append('removePoster', '1');
                }
                return fd;
            }

            fd.append('action', 'create');
            fd.append('name', el.name.value.trim());
            fd.append('tournamentDate', el.date.value);
            fd.append('venue', el.venue.value.trim());
            fd.append('location', el.location.value.trim());
            fd.append('postCode', el.postcode.value.trim());
            fd.append('categoriesOffered', el.categories.value.trim());
            fd.append('organiserName', el.organiser.value.trim());
            appendExtras(fd);
            if (state.file) fd.append('poster', state.file);
            return fd;
        }

        reset();

        return {
            reset: reset,
            loadForEdit: loadForEdit,
            organiserChange: organiserChange,
            coreChanges: coreChanges,
            validate: validate,
            buildFormData: buildFormData,
            isEdit: function () { return state.mode === 'edit'; }
        };
    }

    window.KBFTournamentForm = { mount: mount };
})();
