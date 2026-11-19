// ============================================
// SESSION TIMEOUT MANAGER
// Signs staff out after the idle time set on the Security page and warns
// before it ends. Both times come from /api/auth/session.php
// (session.timeoutMinutes, session.warningMinutes), so the server's timeout
// stays the real limit. 120 and 5 minutes are used only until the server has
// answered.
//
// Every open admin or organiser tab shares one "last activity" time:
// activity in any tab counts for all of them. Tabs talk through a
// BroadcastChannel, with the localStorage 'storage' event as a fallback.
// When the browser blocks both, each tab simply keeps its own time.
// The warning shows in every open tab; "Stay signed in" in one tab closes it
// in all of them. A tab signs out only when the shared last activity is past
// the limit, and then tells the other tabs so they go to the sign-in page.
// ============================================

const KBF_SESSION_KEY = 'kbf-session-activity';
const KBF_SESSION_EVENT_KEY = 'kbf-session-event';

class SessionManager {
    constructor(config = {}) {
        // Configuration (in milliseconds); replaced by the server's values
        this.SESSION_LIFETIME = config.sessionLifetime || 120 * 60 * 1000;
        this.WARNING_TIME = config.warningTime || 5 * 60 * 1000;
        this.CHECK_INTERVAL = config.checkInterval || 15 * 1000;
        this.SHARE_INTERVAL = 5 * 1000;          // tell other tabs at most this often
        this.SERVER_REFRESH_INTERVAL = 60 * 1000; // refresh the server session at most this often

        // State
        this.tabId = Math.random().toString(36).slice(2) + Date.now().toString(36);
        this.lastActivity = Date.now();
        this.lastShared = 0;
        this.lastServerRefresh = 0;
        this.warningShown = false;
        this.loggingOut = false;
        this.checkTimer = null;
        this.warningTimer = null;
        this.channel = null;
        this.originalFetch = window.fetch ? window.fetch.bind(window) : null;

        // Activity event types to monitor
        this.activityEvents = ['mousedown', 'keydown', 'scroll', 'touchstart'];

        // Bind methods
        this.handleActivity = this.handleActivity.bind(this);
        this.checkSession = this.checkSession.bind(this);
        this.showWarning = this.showWarning.bind(this);
        this.extendSession = this.extendSession.bind(this);
        this.logout = this.logout.bind(this);
        this.onShared = this.onShared.bind(this);
        this.onStorage = this.onStorage.bind(this);

        // Initialize
        this.init();
    }

    // ============================================
    // INITIALIZATION
    // ============================================
    init() {
        // Register activity listeners
        this.activityEvents.forEach(event => {
            document.addEventListener(event, this.handleActivity, { passive: true, capture: true });
        });

        // Monitor AJAX requests (fetch API)
        this.interceptFetch();

        // Share activity with the other open tabs
        this.connectTabs();
        const shared = this.readSharedActivity();
        if (shared > this.lastActivity) this.lastActivity = shared;
        this.share('activity', true);
        this.post({ type: 'hello' });

        // Create warning modal (hidden initially)
        this.createWarningModal();

        // Use the server's session times, then start checking
        this.extendSessionOnServer().then(() => this.startChecking());
    }

    // ============================================
    // SHARING BETWEEN TABS
    // ============================================
    storage() {
        try { return window.localStorage || null; } catch (e) { return null; }
    }

    readSharedActivity() {
        try {
            const s = this.storage();
            const value = s ? parseInt(s.getItem(KBF_SESSION_KEY), 10) : NaN;
            return value > 0 ? value : 0;
        } catch (e) {
            return 0;
        }
    }

    connectTabs() {
        try {
            if (typeof BroadcastChannel === 'function') {
                this.channel = new BroadcastChannel('kbf-session');
                this.channel.onmessage = (e) => this.onShared(e.data);
            }
        } catch (e) {
            this.channel = null;
        }
        // Fallback (and for browsers without BroadcastChannel)
        window.addEventListener('storage', this.onStorage);
    }

    // Send a message to the other tabs
    post(message) {
        const msg = Object.assign({ from: this.tabId, at: Date.now() }, message);
        if (this.channel) {
            try { this.channel.postMessage(msg); return; } catch (e) { /* fall back to storage */ }
        }
        try {
            const s = this.storage();
            if (s) s.setItem(KBF_SESSION_EVENT_KEY, JSON.stringify(msg));
        } catch (e) { /* storage blocked: this tab works on its own */ }
    }

    // Store and broadcast this tab's last activity
    share(type, force) {
        const now = Date.now();
        if (!force && now - this.lastShared < this.SHARE_INTERVAL) return;
        this.lastShared = now;
        try {
            const s = this.storage();
            if (s && this.readSharedActivity() < this.lastActivity) {
                s.setItem(KBF_SESSION_KEY, String(this.lastActivity));
            }
        } catch (e) { /* storage blocked */ }
        this.post({ type: type || 'activity', lastActivity: this.lastActivity });
    }

    onStorage(e) {
        if (!e) return;
        if (e.key === KBF_SESSION_KEY) {
            const value = parseInt(e.newValue, 10);
            if (value > 0) this.onShared({ type: 'activity', lastActivity: value });
            return;
        }
        if (e.key === KBF_SESSION_EVENT_KEY && e.newValue) {
            try { this.onShared(JSON.parse(e.newValue)); } catch (err) { /* ignore */ }
        }
    }

    // A message from another tab
    onShared(msg) {
        if (!msg || msg.from === this.tabId || this.loggingOut) return;
        if (msg.type === 'hello') {
            // A new tab asks for the latest activity time
            this.post({ type: 'activity', lastActivity: this.lastActivity });
            return;
        }
        if (msg.type === 'logout') {
            this.leave(msg.reason);
            return;
        }
        const value = parseInt(msg.lastActivity, 10);
        if (value > this.lastActivity) this.lastActivity = Math.min(value, Date.now());
        if (msg.type === 'continue' || (this.warningShown && this.timeLeft() > this.WARNING_TIME)) {
            this.hideWarning();
        }
    }

    timeLeft() {
        const shared = this.readSharedActivity();
        if (shared > this.lastActivity) this.lastActivity = Math.min(shared, Date.now());
        return this.SESSION_LIFETIME - (Date.now() - this.lastActivity);
    }

    // Take the timeout and warning from a session.php response
    applyServerTimes(data) {
        const s = data && data.session;
        if (!s) return;
        const timeout = parseInt(s.timeoutMinutes, 10);
        const warning = parseInt(s.warningMinutes, 10);
        if (timeout > 0) this.SESSION_LIFETIME = timeout * 60 * 1000;
        if (warning > 0 && warning * 60 * 1000 < this.SESSION_LIFETIME) this.WARNING_TIME = warning * 60 * 1000;
    }
    
    // ============================================
    // ACTIVITY TRACKING
    // ============================================
    handleActivity(e) {
        if (this.loggingOut) return;
        // While the warning is open only "Stay signed in" keeps the session
        // (a stray scroll or key press must not dismiss it silently)
        if (this.warningShown && e && e.type) return;
        const now = Date.now();

        // Only update if more than 1 second has passed (debounce)
        if (now - this.lastActivity > 1000) {
            this.lastActivity = now;
            this.share('activity', false);

            // Keep the server session alive while someone is working
            if (now - this.lastServerRefresh > this.SERVER_REFRESH_INTERVAL) {
                this.extendSessionOnServer();
            }
        }
    }

    // Intercept fetch requests to track API activity
    interceptFetch() {
        const originalFetch = window.fetch;
        const self = this;

        window.fetch = function(...args) {
            // Mark as activity
            self.handleActivity();

            // Call original fetch
            return originalFetch.apply(this, args);
        };
    }

    // ============================================
    // SESSION CHECKING
    // ============================================
    startChecking() {
        // Check immediately
        this.checkSession();

        // Then check regularly
        this.checkTimer = setInterval(this.checkSession, this.CHECK_INTERVAL);
    }

    checkSession() {
        if (this.loggingOut) return;
        const timeUntilExpiry = this.timeLeft();

        // Shared last activity is past the limit: sign out
        if (timeUntilExpiry <= 0) {
            this.logout('timeout');
            return;
        }

        if (timeUntilExpiry <= this.WARNING_TIME) {
            if (!this.warningShown) this.showWarning(timeUntilExpiry);
        } else if (this.warningShown) {
            // Someone was active in another tab
            this.hideWarning();
        }
    }
    
    // ============================================
    // WARNING MODAL
    // ============================================
    createWarningModal() {
        const modalHTML = `
            <div id="sessionWarningModal" class="hidden fixed inset-0 bg-black bg-opacity-80 z-[9999] flex items-center justify-center p-4"
                 role="alertdialog" aria-modal="true" aria-labelledby="sessionWarningTitle" aria-describedby="sessionWarningText">
                <div class="bg-slate-900 border-4 border-yellow-500 max-w-md w-full p-6 shadow-2xl">
                    <div class="flex items-center mb-4">
                        <svg class="w-8 h-8 text-yellow-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <h2 id="sessionWarningTitle" class="text-xl font-black uppercase text-yellow-500">You will be signed out soon</h2>
                    </div>

                    <p id="sessionWarningText" class="text-gray-300 font-bold text-sm mb-6">
                        You have not used the site for a while. You will be signed out in <span id="sessionTimeRemaining" class="text-yellow-400 font-black">5:00</span> unless you choose to stay signed in.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-3">
                        <button type="button" data-session-stay
                                class="flex-1 px-6 py-3 theme-primary-bg text-slate-900 font-black uppercase text-sm hover:opacity-90 transition" style="background:var(--theme-primary);color:#0F172A;">
                            Stay signed in
                        </button>
                        <button type="button" data-session-signout
                                class="flex-1 px-6 py-3 bg-slate-800 border-2 border-gray-700 text-gray-300 font-black uppercase text-sm hover:border-red-500 hover:text-red-400 transition">
                            Sign out
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        const modal = document.getElementById('sessionWarningModal');
        modal.querySelector('[data-session-stay]').addEventListener('click', () => this.extendSession());
        modal.querySelector('[data-session-signout]').addEventListener('click', () => this.logout());
    }

    showWarning(timeRemaining) {
        this.warningShown = true;
        const modal = document.getElementById('sessionWarningModal');

        if (modal) {
            this.renderCountdown(timeRemaining);
            modal.classList.remove('hidden');
            if (window.KBFDialog) {
                // Escape means "stay signed in"
                window.KBFDialog.open(modal, {
                    role: 'alertdialog',
                    onClose: () => this.extendSession(),
                    initialFocus: '[data-session-stay]'
                });
            } else {
                const stay = modal.querySelector('[data-session-stay]');
                if (stay) stay.focus();
            }

            // Start countdown
            this.updateWarningCountdown();
        }
    }

    renderCountdown(timeLeft) {
        const timeDisplay = document.getElementById('sessionTimeRemaining');
        const ms = Math.max(0, timeLeft);
        const minutes = Math.floor(ms / 60000);
        const seconds = Math.floor((ms % 60000) / 1000);
        if (timeDisplay) {
            timeDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }
    }

    // Counts down from the shared last activity, so activity in another tab
    // (or "Stay signed in" there) is seen here within a second
    updateWarningCountdown() {
        // Clear existing timer
        if (this.warningTimer) {
            clearInterval(this.warningTimer);
        }

        // Update every second
        this.warningTimer = setInterval(() => {
            const timeLeft = this.timeLeft();

            if (timeLeft <= 0) {
                clearInterval(this.warningTimer);
                this.logout('timeout');
                return;
            }
            if (timeLeft > this.WARNING_TIME) {
                this.hideWarning();
                return;
            }
            this.renderCountdown(timeLeft);
        }, 1000);
    }

    hideWarning() {
        this.warningShown = false;
        const modal = document.getElementById('sessionWarningModal');

        if (modal) {
            modal.classList.add('hidden');
            if (window.KBFDialog) window.KBFDialog.close(modal);
        }

        // Clear countdown timer
        if (this.warningTimer) {
            clearInterval(this.warningTimer);
            this.warningTimer = null;
        }
    }

    // ============================================
    // SESSION ACTIONS
    // ============================================
    // "Stay signed in": closes the warning in every tab and refreshes the
    // server session once (from this tab only)
    extendSession() {
        this.lastActivity = Date.now();
        this.hideWarning();
        this.share('continue', true);
        this.extendSessionOnServer();
    }

    async extendSessionOnServer() {
        this.lastServerRefresh = Date.now();
        try {
            // Touch session.php to extend server-side session; it also returns
            // the current session times. The original fetch is used so this
            // call does not count as activity.
            const doFetch = this.originalFetch || window.fetch;
            const res = await doFetch('/api/auth/session.php', {
                method: 'GET',
                credentials: 'include'
            });
            const data = await res.json().catch(() => null);
            this.applyServerTimes(data);
        } catch (error) {
            console.error('Failed to extend session:', error);
        }
    }

    stopWatching() {
        if (this.checkTimer) clearInterval(this.checkTimer);
        if (this.warningTimer) clearInterval(this.warningTimer);
        this.activityEvents.forEach(event => {
            document.removeEventListener(event, this.handleActivity, { capture: true });
        });
        window.removeEventListener('storage', this.onStorage);
    }

    // Another tab signed out: go to the sign-in page without calling the
    // server again
    leave(reason) {
        if (this.loggingOut) return;
        this.loggingOut = true;
        this.stopWatching();
        if (this.channel) { try { this.channel.close(); } catch (e) { /* ignore */ } }
        try { sessionStorage.clear(); } catch (e) { /* ignore */ }
        window.location.href = reason === 'timeout' ? '/login?reason=timeout' : '/login';
    }

    // reason 'timeout': the sign-in page then says the session ended
    async logout(reason) {
        if (this.loggingOut) return;

        // A timeout is only real when the shared last activity is past the
        // limit (another tab may have been in use)
        if (reason === 'timeout' && this.timeLeft() > 0) {
            this.checkSession();
            return;
        }
        this.loggingOut = true;
        this.stopWatching();

        // Tell the other tabs first so they stop and follow
        this.post({ type: 'logout', reason: reason === 'timeout' ? 'timeout' : 'signout' });
        if (this.channel) { try { this.channel.close(); } catch (e) { /* ignore */ } }

        // Call server logout
        try {
            const doFetch = this.originalFetch || window.fetch;
            await doFetch('/api/auth/logout.php', {
                method: 'POST',
                credentials: 'include'
            });
        } catch (error) {
            console.error('Logout error:', error);
        }

        // Clear this tab's stored session data
        try { sessionStorage.clear(); } catch (e) { /* ignore */ }

        // Redirect to login
        window.location.href = reason === 'timeout' ? '/login?reason=timeout' : '/login';
    }

    // ============================================
    // CLEANUP
    // ============================================
    destroy() {
        this.stopWatching();
        if (this.channel) { try { this.channel.close(); } catch (e) { /* ignore */ } }

        // Remove modal
        const modal = document.getElementById('sessionWarningModal');
        if (modal) {
            if (window.KBFDialog) window.KBFDialog.close(modal);
            modal.remove();
        }
    }
}

// ============================================
// GLOBAL INSTANCE
// Initialize automatically when script loads
// ============================================
let sessionManager = null;

// Only initialize on authenticated pages (not login/register pages)
if (window.location.pathname !== '/login.html' && 
    window.location.pathname !== '/register.html' &&
    window.location.pathname !== '/forgot-password.html' &&
    window.location.pathname !== '/reset-password.html') {
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            sessionManager = new SessionManager();
        });
    } else {
        sessionManager = new SessionManager();
    }
}