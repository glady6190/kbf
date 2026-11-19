<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }

// UK time everywhere: PHP's "today", season boundaries and the career-only
// cutoff use Europe/London; the database session is set to the same offset
// below, so NOW() and CURDATE() agree with PHP.
date_default_timezone_set('Europe/London');

// Log a message only once a day (per message), so a missing setting is
// reported clearly without filling the error log. The marker files live in
// storage/ (blocked from the web). Falls back to logging every time when
// storage/ cannot be written.
function kbfLogOnce($message) {
    $dir = dirname(__DIR__) . '/storage/log-once';
    $marker = $dir . '/' . md5((string) $message);
    if (is_file($marker) && filemtime($marker) > time() - 86400) {
        return;
    }
    error_log('KBF: ' . $message);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @touch($marker);
}

// Load environment variables from .env file (never commit .env to version control)
(function() {
    $envFile = dirname(__DIR__) . '/.env';
    if (!file_exists($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
})();

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Required .env values: a clear message (names only, never values) when one
// is missing. The site then falls back safely: no database connection
// ("Service temporarily unavailable"), no emails sent, registration
// refused, 2-step setup unavailable, or an empty fingerprint salt.
(function () {
    $required = [
        'DB_NAME' => 'the site cannot connect to the database',
        'DB_USER' => 'the site cannot connect to the database',
        'DB_PASS' => 'the site cannot connect to the database',
        'SESSION_FINGERPRINT_SALT' => 'sessions use an empty fingerprint salt',
        'SMTP_PASSWORD' => 'no email can be sent',
        'RECAPTCHA_SECRET_KEY' => 'every registration is refused',
        'TWO_FACTOR_KEY' => 'nobody can set up 2-step verification',
    ];
    foreach ($required as $key => $effect) {
        if ((string) getenv($key) === '') {
            kbfLogOnce('.env value ' . $key . ' is missing: ' . $effect . '. Add it to .env (see .env.example).');
        }
    }
})();

define('ALLOWED_ORIGINS', [
    'https://kbf-uk.org'
]);

// Create database connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    // The same behaviour on any server: UK time (PHP's current offset, e.g.
    // +01:00 in summer, +00:00 in winter) and a known sql_mode. Times stored
    // before this were written in UTC and are left as they are.
    $pdo->exec("SET time_zone = '" . date('P') . "', sql_mode = 'NO_ENGINE_SUBSTITUTION'");
} catch(PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    die(json_encode(['error' => 'Service temporarily unavailable']));
}

// Security configuration: the sign-in rules on the Security page
// (Settings, read once per request), with today's values as fallbacks
require_once __DIR__ . '/config-helpers.php';
define('SESSION_LIFETIME', kbfSettingInt('session_timeout_minutes', 120, 15, 720) * 60);
define('MAX_LOGIN_ATTEMPTS', kbfSettingInt('login_lockout_attempts_email', 5, 3, 20));      // failed attempts per email within LOCKOUT_TIME
define('MAX_LOGIN_ATTEMPTS_PER_IP', kbfSettingInt('login_lockout_attempts_ip', 20, 10, 200)); // failed attempts per IP address within LOCKOUT_TIME
define('LOCKOUT_TIME', kbfSettingInt('login_lockout_minutes', 15, 5, 120) * 60);

// Never show PHP errors to visitors; log them instead
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// Force HTTPS (web requests only), before the session starts. The redirect
// goes to the Website address (site_base_url), never to the request's Host
// header. Proxy-aware: the forwarded-proto header counts as HTTPS too.
if (PHP_SAPI !== 'cli') {
    $kbfIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$kbfIsHttps) {
        $kbfUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($kbfUri === '' || $kbfUri[0] !== '/' || (isset($kbfUri[1]) && ($kbfUri[1] === '/' || $kbfUri[1] === '\\'))) {
            $kbfUri = '/';
        }
        header('Location: ' . kbfSiteBaseUrl() . preg_replace('/[\r\n]/', '', $kbfUri), true, 301);
        exit;
    }
    unset($kbfIsHttps);
}

// A signed-in session ends this long after sign-in, however busy it is
define('SESSION_ABSOLUTE_LIFETIME', 12 * 3600);

// Sessions on read-only requests (PERF-07). A PHP file session is locked for
// the whole request, so parallel calls from one browser would run one after
// another. Sign-in endpoints (api/auth/) keep the full behaviour: they write
// to the session on GET (security token, clean-up of sign-in states).
//  - GET/HEAD with no session cookie: no session is started; $_SESSION is an
//    empty array, so every check sees a signed-out visitor.
//  - GET/HEAD with a session cookie: the session starts (inactivity timeout,
//    12-hour limit, impersonation and account checks all run as before) and
//    is closed at the end of this file, so the lock is released at once.
// Anything that must write later calls kbfSessionWritable() first.
function kbfIsSessionKeptOpen() {
    if (PHP_SAPI === 'cli' || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        return true;
    }
    if (defined('KBF_SESSION_WRITE')) {
        return true;
    }
    $script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
    return $script !== false && basename(dirname($script)) === 'auth'
        && realpath(dirname($script, 2)) === realpath(__DIR__);
}

// (Re)open the session for writing when it is not active (read-only request
// whose session was closed early or never started). The stored session is
// read again, so changes made meanwhile by another request are kept.
// Returns true when the session is active afterwards.
function kbfSessionWritable() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return false;
    }
    @session_start();
    return session_status() === PHP_SESSION_ACTIVE;
}

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');

    // Enable secure flag if HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
}

define('KBF_SESSION_KEPT_OPEN', kbfIsSessionKeptOpen());

if (session_status() === PHP_SESSION_NONE && !KBF_SESSION_KEPT_OPEN && empty($_COOKIE[session_name()])) {
    // Anonymous read: treated as signed out, no session file is created
    $_SESSION = [];
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
    
    // Session timeout check
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_LIFETIME)) {
        session_unset();
        session_destroy();
        session_start();
    }
    // Absolute limit: 12 hours from sign-in. A session signed in before this
    // check existed starts its 12 hours now.
    if (isset($_SESSION['user_id']) && !isset($_SESSION['SIGNED_IN_AT'])) {
        $_SESSION['SIGNED_IN_AT'] = time();
    }
    if (isset($_SESSION['SIGNED_IN_AT']) && (time() - (int) $_SESSION['SIGNED_IN_AT'] > SESSION_ABSOLUTE_LIFETIME)) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['LAST_ACTIVITY'] = time();
    
    // Regenerate session ID periodically (every 30 minutes)
    if (!isset($_SESSION['CREATED'])) {
        $_SESSION['CREATED'] = time();
    } else if (time() - $_SESSION['CREATED'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['CREATED'] = time();
    }
}

// CORS configuration - SECURE
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, ALLOWED_ORIGINS)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // For same-origin requests or allowed origins only
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGINS[0]);
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Input types (web requests only): no query or form value is ever a list,
// so a value sent as one (e.g. ?id[]=1) is a 400. Uploaded files are in
// $_FILES and are not affected. A wrong type that still reaches a PHP
// function (TypeError) is answered with a 400, never a PHP error; any other
// uncaught error is logged and answered with a plain 500.
if (PHP_SAPI !== 'cli') {
    foreach ([$_GET, $_POST] as $kbfInputSource) {
        foreach ($kbfInputSource as $kbfInputKey => $kbfInputValue) {
            if (is_array($kbfInputValue)) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid value for ' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $kbfInputKey)]);
                exit;
            }
        }
    }
    unset($kbfInputSource, $kbfInputKey, $kbfInputValue);

    set_exception_handler(function ($e) {
        $isType = ($e instanceof TypeError) || ($e instanceof ValueError);
        error_log(($isType ? 'Invalid input: ' : 'Uncaught error: ') . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            if (function_exists('kbfNoStore')) {
                kbfNoStore();
            }
            http_response_code($isType ? 400 : 500);
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'message' => $isType ? 'Invalid request' : 'An error occurred. Please try again later.']);
    });
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');


// Helper function to check if user is logged in.
// The account is re-read from the database (once per request): a user who no
// longer exists, has been deactivated, has had sign-in turned off or is no
// longer staff is logged out immediately.
// The browser fingerprint (user agent only, so a phone whose IP address
// changes stays signed in) must match the one taken at sign-in.
function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_fingerprint'])) {
        return false;
    }
    if (!hash_equals((string) $_SESSION['user_fingerprint'], generateUserFingerprint())) {
        kbfEndSession();
        return false;
    }
    return refreshSessionUser();
}

// End the session so this request is treated as signed out
function kbfEndSession() {
    if (!kbfSessionWritable()) {
        $_SESSION = [];
        return;
    }
    session_unset();
    session_destroy();
    if (!headers_sent()) {
        session_start();
    } else {
        $_SESSION = [];
    }
}

// A request that only reads (GET, HEAD, OPTIONS)
function kbfIsReadOnlyRequest() {
    return in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true);
}

// Users.SessionVersion for a user: 0 when the column does not exist yet
// (database/security-hardening.sql not run). Throws PDOException when the
// database cannot be reached.
function kbfReadSessionVersion($userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT SessionVersion FROM Users WHERE UserId = ?");
        $stmt->execute([(int) $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : (int) $value;
    } catch (PDOException $e) {
        if (kbfIsMissingColumnError($e)) {
            return 0;
        }
        throw $e;
    }
}

function kbfIsMissingColumnError(PDOException $e) {
    return $e->getCode() === '42S22' || stripos($e->getMessage(), 'Unknown column') !== false;
}

// Sign every session of this user out (password change, reset, temporary
// password, 2-step turned off or reset, "Sign out everywhere"). Returns the
// new version, or null when the column does not exist yet.
function kbfBumpSessionVersion($userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE Users SET SessionVersion = SessionVersion + 1 WHERE UserId = ?");
        $stmt->execute([(int) $userId]);
        return kbfReadSessionVersion($userId);
    } catch (PDOException $e) {
        if (kbfIsMissingColumnError($e)) {
            error_log('kbfBumpSessionVersion: Users.SessionVersion missing; run database/security-hardening.sql');
            return null;
        }
        throw $e;
    }
}

// Start a brand-new session for a person who has passed every sign-in step.
// The whole old session is cleared first (including any impersonation keys).
function kbfBeginUserSession(array $user) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['UserId'];
    $_SESSION['email'] = $user['Email'];
    $_SESSION['role'] = $user['Role'];
    $_SESSION['name'] = $user['FirstName'] . ' ' . $user['LastName'];
    $_SESSION['category_id'] = $user['CategoryId'];
    $_SESSION['club_id'] = $user['ClubId'];
    $_SESSION['user_fingerprint'] = generateUserFingerprint();
    $_SESSION['session_version'] = kbfReadSessionVersion($user['UserId']);
    $_SESSION['SIGNED_IN_AT'] = time();
    $_SESSION['CREATED'] = time();
    $_SESSION['LAST_ACTIVITY'] = time();
}

// Clear the whole session at the start of a sign-in (before a pending or
// temporary state is set)
function kbfClearSessionForSignIn() {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

// Re-reads the session user's account, once per request per user.
// Returns false (after ending the session) when the user is gone, inactive,
// cannot sign in any more, or is no longer staff (see KBF_CAN_SIGN_IN_SQL).
function refreshSessionUser() {
    static $checkedUserId = null;
    global $pdo;

    $userId = $_SESSION['user_id'];
    if ($checkedUserId !== null && $checkedUserId === $userId) {
        return true;
    }

    // Impersonation: the real person behind the session is the Super Admin.
    // Ends automatically after the impersonation time on the Security page, or
    // when the person being viewed is no longer active staff. The Super Admin
    // must still be able to sign in.
    if (isImpersonating()) {
        if (impersonationSecondsLeft() <= 0) {
            endImpersonation('ended automatically after ' . (int) (kbfImpersonationSeconds() / 60) . ' minutes');
            $userId = $_SESSION['user_id'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT u.UserId FROM Users u
                    WHERE u.UserId = ? AND u.IsSuperAdmin = 1 AND " . KBF_CAN_SIGN_IN_SQL . "
                ");
                $stmt->execute([impersonatorUserId()]);
                $impersonatorOk = (bool) $stmt->fetch();
                // The Super Admin's own sessions were signed out everywhere
                if ($impersonatorOk && kbfReadSessionVersion(impersonatorUserId())
                        !== (int) ($_SESSION['impersonator']['session_version'] ?? 0)) {
                    $impersonatorOk = false;
                }

                $stmt = $pdo->prepare("
                    SELECT u.Role FROM Users u
                    WHERE u.UserId = ? AND u.IsActive = 1 AND u.IsSuperAdmin = 0
                      AND EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId)
                ");
                $stmt->execute([$userId]);
                $target = $stmt->fetch();
            } catch (PDOException $e) {
                // Could not check: reading pages carry on, anything that
                // writes data is treated as signed out
                error_log('Impersonation refresh failed: ' . $e->getMessage());
                return kbfIsReadOnlyRequest();
            }

            if (!$impersonatorOk) {
                // The Super Admin can no longer sign in: end everything
                $checkedUserId = null;
                kbfEndSession();
                return false;
            }

            if (!$target) {
                endImpersonation('the person is no longer available to view');
                $userId = $_SESSION['user_id'];
            } else {
                $_SESSION['role'] = $target['Role'];
                $checkedUserId = $userId;
                return true;
            }
        }
    }

    try {
        $stmt = $pdo->prepare("
            SELECT u.IsActive, u.Role,
                   (CASE WHEN " . KBF_CAN_SIGN_IN_SQL . " THEN 1 ELSE 0 END) AS CanSignIn
            FROM Users u
            WHERE u.UserId = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $sessionVersion = $user ? kbfReadSessionVersion($userId) : 0;
    } catch (PDOException $e) {
        // Could not check: reading pages carry on, anything that writes data
        // is treated as signed out (the session itself is kept)
        error_log('Session user refresh failed: ' . $e->getMessage());
        return kbfIsReadOnlyRequest();
    }

    // Signed out everywhere (password changed or reset, temporary password,
    // 2-step turned off or reset, or "Sign out everywhere")
    $versionOk = $user && $sessionVersion === (int) ($_SESSION['session_version'] ?? 0);

    if (!$user || (int) $user['IsActive'] !== 1 || (int) $user['CanSignIn'] !== 1 || !$versionOk) {
        // End the session so this request is treated as logged out
        // (same pattern as the inactivity timeout above)
        $checkedUserId = null;
        kbfEndSession();
        return false;
    }

    // Kept for display only; the role never decides access
    $_SESSION['role'] = $user['Role'];
    $checkedUserId = $userId;
    return true;
}

// Helper function to require authentication
function requireAuth() {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Authentication required'], 401);
    }
}

// Helper function to send JSON response
function sendJSON($data, $status = 200) {
    if (($status >= 300 || (is_array($data) && isset($data['success']) && $data['success'] === false))
            && function_exists('kbfNoStore')) {
        kbfNoStore();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// CSRF Token generation and validation
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        kbfSessionWritable();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Rate limiting for login attempts.
// Only FAILED attempts count: at most MAX_LOGIN_ATTEMPTS per email and
// MAX_LOGIN_ATTEMPTS_PER_IP per IP address within LOCKOUT_TIME.
function checkLoginAttempts($email, $ipAddress = null) {
    global $pdo;

    // Clean up old FAILED attempts only (older than lockout time)
    $stmt = $pdo->prepare("
        DELETE FROM LoginAttempts
        WHERE Success = 0
        AND AttemptTime < DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([LOCKOUT_TIME]);

    // Count recent failed attempts for this email
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as attempts
        FROM LoginAttempts
        WHERE Email = ? AND Success = 0 AND AttemptTime > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$email, LOCKOUT_TIME]);
    $result = $stmt->fetch();

    if ($result['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        return false;
    }

    // Count recent failed attempts from this IP address (any email)
    if ($ipAddress === null) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as attempts
        FROM LoginAttempts
        WHERE IpAddress = ? AND Success = 0 AND AttemptTime > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$ipAddress, LOCKOUT_TIME]);
    $result = $stmt->fetch();

    return $result['attempts'] < MAX_LOGIN_ATTEMPTS_PER_IP;
}

function recordLoginAttempt($email, $success) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO LoginAttempts (Email, Success, IpAddress, AttemptTime) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([
        $email, 
        $success ? 1 : 0, 
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // If successful, clear failed attempts
    if ($success) {
        $stmt = $pdo->prepare("DELETE FROM LoginAttempts WHERE Email = ? AND Success = 0");
        $stmt->execute([$email]);
    }
}

// Input sanitization
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Generate user fingerprint for session validation. User agent only: the IP
// address is left out so mobile users whose address changes stay signed in.
function generateUserFingerprint() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $salt = getenv('SESSION_FINGERPRINT_SALT') ?: '';
    return hash('sha256', 'ua:' . $userAgent . $salt);
}

// ============================================
// REQUEST LIMITS (table RequestLimits, database/security-hardening.sql)
// Each counted request is one row. Rows are only ever removed when they are
// more than a day old, never when a reset token is issued.
// $key is hashed, so emails and IP addresses are not stored in plain text.
// ============================================

// How many requests in this bucket for this key in the last $windowSeconds.
// Returns null when the table does not exist yet.
function kbfRequestCount($bucket, $key, $windowSeconds) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM RequestLimits
            WHERE Bucket = ? AND KeyHash = ? AND CreatedAt > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([(string) $bucket, hash('sha256', (string) $key), (int) $windowSeconds]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('kbfRequestCount: ' . $e->getMessage());
        return null;
    }
}

// Count one request (and tidy rows older than a day now and then)
function kbfRequestRecord($bucket, $key) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO RequestLimits (Bucket, KeyHash, CreatedAt) VALUES (?, ?, NOW())");
        $stmt->execute([(string) $bucket, hash('sha256', (string) $key)]);
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM RequestLimits WHERE CreatedAt < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    } catch (PDOException $e) {
        error_log('kbfRequestRecord: ' . $e->getMessage());
    }
}

// Read a JSON request body as an array; anything else is a 400
function kbfJsonBody() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        sendJSON(['success' => false, 'message' => 'Invalid request body'], 400);
    }
    return $data;
}

// Input type checks: a wrong type is a 400, never a PHP error.
// Missing (or null) values are returned as $default.
function kbfInputString(array $data, $key, $default = '') {
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return $default;
    }
    if (is_int($data[$key]) || is_float($data[$key])) {
        return (string) $data[$key];
    }
    if (!is_string($data[$key])) {
        sendJSON(['success' => false, 'message' => 'Invalid value for ' . $key], 400);
    }
    return $data[$key];
}

function kbfInputArray(array $data, $key, $default = []) {
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return $default;
    }
    if (!is_array($data[$key])) {
        sendJSON(['success' => false, 'message' => 'Invalid value for ' . $key], 400);
    }
    return $data[$key];
}

// Reject any value in a JSON body (top level) that is an array or object,
// except the keys listed in $arrayKeys. Used by endpoints that read every
// field as text or numbers.
function kbfRequireScalarFields($data, array $arrayKeys = []) {
    if (!is_array($data)) {
        return;
    }
    foreach ($data as $k => $v) {
        if (is_array($v) && !in_array($k, $arrayKeys, true)) {
            sendJSON(['success' => false, 'message' => 'Invalid value for ' . $k], 400);
        }
    }
}

// Per-user permissions, staff role labels and audit logging
require_once __DIR__ . '/permissions.php';

// 2-step verification (authenticator app) and the pending sign-in state
require_once __DIR__ . '/two-factor.php';

// Re-check the logged-in account on every request, before any endpoint reads
// the session, so a deactivated user is logged out immediately.
if (isset($_SESSION['user_id'])) {
    isLoggedIn();
}

// Read-only request (not a sign-in endpoint): the access check above is done
// and nothing else writes to the session, so release the session lock now.
// The values stay readable in $_SESSION for the rest of the request.
if (!KBF_SESSION_KEPT_OPEN && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>