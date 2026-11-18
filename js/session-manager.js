// ============================================
// SESSION TIMEOUT MANAGER
// Automatically logs out users after the idle time set on the Security page
// and warns before it ends. Both times come from /api/auth/session.php
// (session.timeoutMinutes, session.warningMinutes), so the browser and the
// server can never disagree. 120 and 5 minutes are used only until the
// server has answered.
// ============================================

class SessionManager {
    constructor(config = {}) {
        // Configuration (in milliseconds); replaced by the server's values
        this.SESSION_LIFETIME = config.sessionLifetime || 120 * 60 * 1000;
        this.WARNING_TIME = config.warningTime || 5 * 60 * 1000;
        this.CHECK_INTERVAL = config.checkInterval || 60 * 1000; // Check every minute
        
        // State
        this.lastActivity = Date.now();
        this.warningShown = false;
        this.checkTimer = null;
        this.warningTimer = null;
        
        // Activity event types to monitor
        this.activityEvents = ['mousedown', 'keypress', 'scroll', 'touchstart'];
        
        // Bind methods
        this.handleActivity = this.handleActivity.bind(this);
        this.checkSession = this.checkSession.bind(this);
        this.showWarning = this.showWarning.bind(this);
        this.extendSession = this.extendSession.bind(this);
        this.logout = this.logout.bind(this);
        
        // Initialize
        this.init();
    }
    
    // ============================================
    // INITIALIZATION
    // ============================================
    init() {
        // Register activity listeners
        this.activityEvents.forEach(event => {
            document.addEventListener(event, this.handleActivity, { passive: true });
        });
        
        // Monitor AJAX requests (fetch API)
        this.interceptFetch();
        
        // Create warning modal (hidden initially)
        this.createWarningModal();

        // Use the server's session times, then start checking
        this.extendSessionOnServer().then(() => this.startChecking());
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
    handleActivity() {
        const now = Date.now();
        const timeSinceLastActivity = now - this.lastActivity;
        
        // Only update if more than 1 second has passed (debounce)
        if (timeSinceLastActivity > 1000) {
            this.lastActivity = now;
            
            // Hide warning if shown
            if (this.warningShown) {
                this.hideWarning();
            }
            
            // Extend session on server
            this.extendSessionOnServer();
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
        
        // Then check every minute
        this.checkTimer = setInterval(this.checkSession, this.CHECK_INTERVAL);
    }
    
    checkSession() {
        const now = Date.now();
        const timeSinceActivity = now - this.lastActivity;
        const timeUntilExpiry = this.SESSION_LIFETIME - timeSinceActivity;
        
        // Session expired - logout
        if (timeUntilExpiry <= 0) {
            this.logout('timeout');
            return;
        }
        
        // Show warning if approaching expiry
        if (timeUntilExpiry <= this.WARNING_TIME && !this.warningShown) {
            this.showWarning(timeUntilExpiry);
        }
    }
    
    // ============================================
    // WARNING MODAL
    // ============================================
    createWarningModal() {
        const modalHTML = `
            <div id="sessionWarningModal" class="hidden fixed inset-0 bg-black bg-opacity-80 z-[9999] flex items-center justify-center p-4">
                <div class="bg-slate-900 border-4 border-yellow-500 max-w-md w-full p-6 shadow-2xl">
                    <div class="flex items-center mb-4">
                        <svg class="w-8 h-8 text-yellow-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <h3 class="text-xl font-black uppercase text-yellow-500">Session Expiring Soon</h3>
                    </div>
                    
                    <p class="text-gray-300 font-bold uppercase text-sm mb-6">
                        Your session will expire in <span id="sessionTimeRemaining" class="text-yellow-400 font-black">5:00</span> due to inactivity.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-3">
                        <button onclick="sessionManager.extendSession()" 
                                class="flex-1 px-6 py-3 bg-emerald-500 text-slate-900 font-black uppercase text-sm hover:bg-emerald-400 transition">
                            Stay Logged In
                        </button>
                        <button onclick="sessionManager.logout()" 
                                class="flex-1 px-6 py-3 bg-slate-800 border-2 border-gray-700 text-gray-300 font-black uppercase text-sm hover:border-red-500 hover:text-red-400 transition">
                            Logout
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }
    
    showWarning(timeRemaining) {
        this.warningShown = true;
        const modal = document.getElementById('sessionWarningModal');
        
        if (modal) {
            modal.classList.remove('hidden');
            
            // Start countdown
            this.updateWarningCountdown(timeRemaining);
        }
    }
    
    updateWarningCountdown(initialTime) {
        const timeDisplay = document.getElementById('sessionTimeRemaining');
        let timeLeft = initialTime;
        
        // Clear existing timer
        if (this.warningTimer) {
            clearInterval(this.warningTimer);
        }
        
        // Update every second
        this.warningTimer = setInterval(() => {
            timeLeft -= 1000;
            
            if (timeLeft <= 0) {
                clearInterval(this.warningTimer);
                this.logout('timeout');
                return;
            }
            
            const minutes = Math.floor(timeLeft / 60000);
            const seconds = Math.floor((timeLeft % 60000) / 1000);
            
            if (timeDisplay) {
                timeDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            }
        }, 1000);
    }
    
    hideWarning() {
        this.warningShown = false;
        const modal = document.getElementById('sessionWarningModal');
        
        if (modal) {
            modal.classList.add('hidden');
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
    extendSession() {
        this.lastActivity = Date.now();
        this.hideWarning();
        this.extendSessionOnServer();
    }
    
    async extendSessionOnServer() {
        try {
            // Touch session.php to extend server-side session; it also returns
            // the current session times
            const res = await fetch('/api/auth/session.php', {
                method: 'GET',
                credentials: 'include'
            });
            const data = await res.json().catch(() => null);
            this.applyServerTimes(data);
        } catch (error) {
            console.error('Failed to extend session:', error);
        }
    }
    
    // reason 'timeout': the sign-in page then says the session ended
    async logout(reason) {
        // Clear timers
        if (this.checkTimer) clearInterval(this.checkTimer);
        if (this.warningTimer) clearInterval(this.warningTimer);
        
        // Remove event listeners
        this.activityEvents.forEach(event => {
            document.removeEventListener(event, this.handleActivity);
        });
        
        // Call server logout
        try {
            await fetch('/api/auth/logout.php', {
                method: 'POST',
                credentials: 'include'
            });
        } catch (error) {
            console.error('Logout error:', error);
        }
        
        // Clear local storage
        sessionStorage.clear();
        
        // Redirect to login
        window.location.href = reason === 'timeout' ? '/login?reason=timeout' : '/login.html';
    }
    
    // ============================================
    // CLEANUP
    // ============================================
    destroy() {
        // Clear timers
        if (this.checkTimer) clearInterval(this.checkTimer);
        if (this.warningTimer) clearInterval(this.warningTimer);
        
        // Remove event listeners
        this.activityEvents.forEach(event => {
            document.removeEventListener(event, this.handleActivity);
        });
        
        // Remove modal
        const modal = document.getElementById('sessionWarningModal');
        if (modal) modal.remove();
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