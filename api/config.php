<?php
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

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    
    // Enable secure flag if HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    
    session_start();
    
    // Session timeout check
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_LIFETIME)) {
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

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');


// Helper function to check if user is logged in.
// The account is re-read from the database (once per request): a user who no
// longer exists, has been deactivated, has had sign-in turned off or is no
// longer staff is logged out immediately.
function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_fingerprint'])) {
        return false;
    }
    return refreshSessionUser();
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

                $stmt = $pdo->prepare("
                    SELECT u.Role FROM Users u
                    WHERE u.UserId = ? AND u.IsActive = 1 AND u.IsSuperAdmin = 0
                      AND EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId)
                ");
                $stmt->execute([$userId]);
                $target = $stmt->fetch();
            } catch (PDOException $e) {
                error_log('Impersonation refresh failed: ' . $e->getMessage());
                return true;
            }

            if (!$impersonatorOk) {
                // The Super Admin can no longer sign in: end everything
                $checkedUserId = null;
                session_unset();
                session_destroy();
                if (!headers_sent()) {
                    session_start();
                } else {
                    $_SESSION = [];
                }
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
    } catch (PDOException $e) {
        // Could not check: keep the session as before rather than lock everyone out
        error_log('Session user refresh failed: ' . $e->getMessage());
        return true;
    }

    if (!$user || (int) $user['IsActive'] !== 1 || (int) $user['CanSignIn'] !== 1) {
        // End the session so this request is treated as logged out
        // (same pattern as the inactivity timeout above)
        $checkedUserId = null;
        session_unset();
        session_destroy();
        if (!headers_sent()) {
            session_start();
        } else {
            $_SESSION = [];
        }
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
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// CSRF Token generation and validation
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

// Generate user fingerprint for session validation
function generateUserFingerprint() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $salt = getenv('SESSION_FINGERPRINT_SALT') ?: '';
    return hash('sha256', $userAgent . $ipAddress . $salt);
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
?>