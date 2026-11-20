<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
// ============================================
// KBF-UK: PER-USER PERMISSIONS (foundation)
// Included by config.php, so it is available wherever config.php is.
//
// The permission keys and role presets below MUST match the seed in
// database/permissions-foundation.sql exactly.
//
// Adding staff, changing access, impersonation and security settings are not
// permissions: they belong to Super Admins only (requireSuperAdmin()).
//
// Stage 3a: every server-side check uses these permissions. Users.Role and
// Users.ClubId stay in the database but no longer decide anything.
// ============================================

// Every permission key, grouped as in the Permissions table
const KBF_PERMISSION_KEYS = [
    // Players
    'players.approve_registrations',
    'players.view_export',
    'players.edit_details',
    'players.change_grades',
    'players.contact_details',
    // Tournaments
    'tournaments.organise_own',
    'tournaments.approve',
    'tournaments.manage_all',
    // Content
    'content.own_news_gallery',
    'content.all_news_gallery',
    'content.sponsors',
    'content.broadcasts',
    // Control centre (database/email-engine.sql)
    'control.email',
    'control.grading',
    'control.notifications',
    'control.reports',
    'control.site_settings',
    'control.activity_log',
];

// Control centre permissions. Not part of any preset: they are ticked one by
// one for the people who should have them.
const KBF_CONTROL_PERMISSION_KEYS = [
    'control.email',
    'control.grading',
    'control.notifications',
    'control.reports',
    'control.site_settings',
    'control.activity_log',
];

// Staff role labels and the permissions each preset ticks.
// Labels only: permissions decide what people can do.
const KBF_ROLE_PRESETS = [
    'MainAdmin' => [
        'players.approve_registrations',
        'players.view_export',
        'players.edit_details',
        'players.change_grades',
        'players.contact_details',
        'tournaments.organise_own',
        'tournaments.approve',
        'tournaments.manage_all',
        'content.own_news_gallery',
        'content.all_news_gallery',
        'content.sponsors',
        'content.broadcasts',
    ],
    // Approves and rejects new player registrations, nothing else
    'ZonalAdmin' => [
        'players.approve_registrations',
    ],
    'Organiser' => [
        'tournaments.organise_own',
        'content.own_news_gallery',
    ],
];

// Permission keys a role preset ticks (empty array for an unknown role)
function rolePresetPermissions($roleKey) {
    return KBF_ROLE_PRESETS[$roleKey] ?? [];
}

// ============================================
// CURRENT USER'S ACCESS
// Loaded fresh from the database once per request, then cached for the rest
// of that request only. Returns null when nobody is logged in.
// ============================================
function currentAccess() {
    static $cache = [];

    if (!isLoggedIn()) {
        return null;
    }

    $userId = (int) $_SESSION['user_id'];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    global $pdo;
    $access = [
        'userId' => $userId,
        'isActive' => false,
        'isSuperAdmin' => false,
        'permissions' => [],
        'roles' => [],
    ];

    try {
        $stmt = $pdo->prepare("SELECT IsActive, IsSuperAdmin FROM Users WHERE UserId = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user) {
            $access['isActive'] = ((int) $user['IsActive'] === 1);
            $access['isSuperAdmin'] = ((int) $user['IsSuperAdmin'] === 1);

            $stmt = $pdo->prepare("SELECT PermissionKey FROM UserPermissions WHERE UserId = ?");
            $stmt->execute([$userId]);
            $access['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $pdo->prepare("SELECT RoleKey FROM StaffRoles WHERE UserId = ? ORDER BY RoleKey");
            $stmt->execute([$userId]);
            $access['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        // e.g. permissions-foundation.sql not run yet: grant nothing
        error_log('permissions.php: could not load access for user ' . $userId . ': ' . $e->getMessage());
    }

    $cache[$userId] = $access;
    return $access;
}

function isSuperAdmin() {
    $access = currentAccess();
    return $access !== null && $access['isActive'] && $access['isSuperAdmin'];
}

// True for every key when the user is a Super Admin
function hasPermission($key) {
    $access = currentAccess();
    if ($access === null || !$access['isActive']) {
        return false;
    }
    if ($access['isSuperAdmin']) {
        return true;
    }
    return in_array($key, $access['permissions'], true);
}

function requirePermission($key) {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }
    if (!hasPermission($key)) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
}

function requireSuperAdmin() {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }
    if (!isSuperAdmin()) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
}

// Staff role labels (MainAdmin, ZonalAdmin, Organiser) of the current user
function currentStaffRoles() {
    $access = currentAccess();
    return $access === null ? [] : $access['roles'];
}

// Staff = a Super Admin or anyone with at least one permission
function isStaff() {
    $access = currentAccess();
    if ($access === null || !$access['isActive']) {
        return false;
    }
    return $access['isSuperAdmin'] || !empty($access['permissions']);
}

// True when at least one of the given permissions is held
function hasAnyPermission(array $keys) {
    foreach ($keys as $key) {
        if (hasPermission($key)) {
            return true;
        }
    }
    return false;
}

// ============================================
// SIGN-IN RULE
// A person can sign in when they are active, sign-in is enabled, they have a
// password, and they are staff. Used as a WHERE fragment on the Users table
// aliased "u" (a fixed string, never user input).
// ============================================
const KBF_CAN_SIGN_IN_SQL = "u.IsActive = 1
      AND u.LoginEnabled = 1
      AND u.PasswordHash IS NOT NULL AND u.PasswordHash <> ''
      AND (u.IsSuperAdmin = 1
           OR EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId))";

// True when the given user id may sign in under the rule above
function userCanSignIn($userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT u.UserId FROM Users u WHERE u.UserId = ? AND " . KBF_CAN_SIGN_IN_SQL);
        $stmt->execute([(int) $userId]);
        return (bool) $stmt->fetch();
    } catch (PDOException $e) {
        error_log('userCanSignIn failed: ' . $e->getMessage());
        return false;
    }
}

// True when the user (by id) is staff: a Super Admin or holds any permission
function userIsStaff($userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT u.UserId FROM Users u
            WHERE u.UserId = ?
              AND (u.IsSuperAdmin = 1
                   OR EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId))
        ");
        $stmt->execute([(int) $userId]);
        return (bool) $stmt->fetch();
    } catch (PDOException $e) {
        error_log('userIsStaff failed: ' . $e->getMessage());
        // Fail safe: treat as staff so only a Super Admin can change them
        return true;
    }
}

// ============================================
// TOURNAMENT OWNERSHIP AND ORGANISER NAME
// "Own tournament": Tournaments.CreatedByAdminId is the current user.
// Always read from the database, never from the request.
// ============================================

// The tournament row needed for ownership checks, or null when not found
function loadTournamentForAccess($tournamentId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT TournamentId, CreatedByAdminId, Status FROM Tournaments WHERE TournamentId = ?");
    $stmt->execute([(int) $tournamentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// True when the current user created the given tournament row
function isOwnTournamentRow($tournament) {
    if (!$tournament || !isLoggedIn()) {
        return false;
    }
    return (int) $tournament['CreatedByAdminId'] === (int) $_SESSION['user_id'];
}

// SQL for a tournament's organiser: OrganiserName, falling back to the old
// club name when empty. Aliases are fixed strings from the calling code.
function organiserNameSql($tournamentAlias = 't', $clubAlias = 'c') {
    return "COALESCE(NULLIF(TRIM($tournamentAlias.OrganiserName), ''), $clubAlias.Name)";
}

// Validate a free-text organiser name: plain text, one line, 1-150 characters.
// Returns [string|null cleanName, string|null error].
function cleanOrganiserName($value) {
    if (!is_string($value)) {
        return [null, 'Organiser name is required'];
    }
    $text = strip_tags($value);
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    if ($text === '') {
        return [null, 'Organiser name is required'];
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > 150) {
        return [null, 'Organiser name must be 150 characters or fewer'];
    }
    return [$text, null];
}

// ============================================
// AUDIT LOG
// Writes one AuditLog row for the current user and IP address.
// $actorUserId overrides the actor when nobody is logged in (e.g. a password
// reset); 0 means system or unknown. Never pass passwords, tokens or request
// bodies in $details. A logging failure never breaks the request.
// ============================================
//
// While a Super Admin is impersonating, the real Super Admin is recorded as
// ActorUserId and the person being viewed as ActingAsUserId.
function auditLog($action, $targetType = null, $targetId = null, $details = null, $actorUserId = null, $actingAsUserId = null) {
    global $pdo;

    if ($actorUserId === null) {
        if (isImpersonating()) {
            $actorUserId = impersonatorUserId();
            $actingAsUserId = (int) $_SESSION['user_id'];
        } else {
            $actorUserId = isLoggedIn() ? (int) $_SESSION['user_id'] : 0;
        }
    }

    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO AuditLog
            (ActorUserId, ActingAsUserId, Action, TargetType, TargetId, Details, IpAddress, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            (int) $actorUserId,
            $actingAsUserId === null ? null : (int) $actingAsUserId,
            substr((string) $action, 0, 60),
            $targetType === null ? null : substr((string) $targetType, 0, 40),
            $targetId === null ? null : (int) $targetId,
            $details,
            $ipAddress
        ]);
    } catch (PDOException $e) {
        error_log('auditLog failed for action ' . $action . ': ' . $e->getMessage());
    }
}

// ============================================
// IMPERSONATION (Super Admins only)
// While impersonating, the session's signed-in user IS the target, so every
// permission check uses the target's permissions. The Super Admin's own
// session values are kept under $_SESSION['impersonator'].
// Ends automatically after kbfImpersonationSeconds() (Security page setting
// impersonation_minutes, 30 minutes when missing).
// ============================================
function kbfImpersonationSeconds() {
    return kbfSettingInt('impersonation_minutes', 30, 5, 120) * 60;
}

// ============================================
// STAFF COOKIE (maintenance mode)
// "kbf_staff=1" lets a signed-in staff member past the maintenance page in
// .htaccess. It grants nothing else: every API still checks the session.
// Set when a staff session is confirmed, removed on sign-out or when the
// session has ended.
// ============================================
function kbfStaffCookie($on) {
    if (headers_sent()) {
        return;
    }
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    if ($on) {
        // Renewed with a fresh expiry while the staff session is active (at
        // most every 5 minutes), so it never runs out while the person is
        // still signed in and working
        $renewedAt = (int) ($_SESSION['STAFF_COOKIE_AT'] ?? 0);
        if (($_COOKIE['kbf_staff'] ?? '') === '1' && time() - $renewedAt < 300) {
            return;
        }
        setcookie('kbf_staff', '1', [
            'expires' => time() + SESSION_LIFETIME,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['STAFF_COOKIE_AT'] = time();
        }
    } elseif (isset($_COOKIE['kbf_staff'])) {
        setcookie('kbf_staff', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
const KBF_IMPERSONATION_BLOCKED_MESSAGE = 'Not available while viewing as someone else';

// Session values that describe the signed-in user
const KBF_SESSION_USER_KEYS = ['user_id', 'email', 'role', 'name', 'category_id', 'club_id', 'session_version'];

function isImpersonating() {
    return isset($_SESSION['impersonator']) && is_array($_SESSION['impersonator'])
        && isset($_SESSION['impersonator']['user_id']);
}

function impersonatorUserId() {
    return isImpersonating() ? (int) $_SESSION['impersonator']['user_id'] : 0;
}

// Block an action while impersonating (403 with a clear message)
function requireNotImpersonating() {
    if (isImpersonating()) {
        sendJSON(['success' => false, 'message' => KBF_IMPERSONATION_BLOCKED_MESSAGE, 'impersonating' => true], 403);
    }
}

// A user the current Super Admin may impersonate: active, staff with at
// least one permission, not a Super Admin, not themselves.
// Returns the Users row or null.
function loadImpersonationTarget($targetUserId, $superAdminUserId) {
    global $pdo;
    if ((int) $targetUserId === (int) $superAdminUserId) {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.Email, u.Role, u.FirstName, u.LastName, u.CategoryId, u.ClubId
        FROM Users u
        WHERE u.UserId = ?
          AND u.IsActive = 1
          AND u.IsSuperAdmin = 0
          AND EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId)
    ");
    $stmt->execute([(int) $targetUserId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Switch the session to the target. The caller has already checked the
// Super Admin, CSRF, password (and later a 2-step code).
function startImpersonation(array $target) {
    $saved = [];
    foreach (KBF_SESSION_USER_KEYS as $key) {
        $saved[$key] = $_SESSION[$key] ?? null;
    }

    $_SESSION['impersonator'] = $saved;
    $_SESSION['impersonation_started'] = time();

    $_SESSION['user_id'] = (int) $target['UserId'];
    $_SESSION['email'] = $target['Email'];
    $_SESSION['role'] = $target['Role'];
    $_SESSION['name'] = trim($target['FirstName'] . ' ' . $target['LastName']);
    $_SESSION['category_id'] = $target['CategoryId'];
    $_SESSION['club_id'] = $target['ClubId'];
    // Only the Super Admin's own version (kept under 'impersonator') is checked
    unset($_SESSION['session_version']);

    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();

    auditLog('impersonation_started', 'user', (int) $target['UserId'],
        'Viewing as ' . $_SESSION['name'], (int) $saved['user_id'], (int) $target['UserId']);
}

// Restore the Super Admin's own session. Returns the target's user id.
// Never calls isLoggedIn() (it is used from inside the session refresh).
function endImpersonation($reason) {
    if (!isImpersonating()) {
        return 0;
    }
    $targetUserId = (int) $_SESSION['user_id'];
    $targetName = (string) ($_SESSION['name'] ?? '');
    $saved = $_SESSION['impersonator'];
    $superAdminId = (int) $saved['user_id'];

    foreach (KBF_SESSION_USER_KEYS as $key) {
        $_SESSION[$key] = $saved[$key] ?? null;
    }
    unset($_SESSION['impersonator'], $_SESSION['impersonation_started']);

    if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['CREATED'] = time();
    }

    auditLog('impersonation_ended', 'user', $targetUserId,
        'Stopped viewing as ' . $targetName . '; reason: ' . $reason, $superAdminId, $targetUserId);

    return $targetUserId;
}

// Seconds of impersonation left (0 when not impersonating or expired)
function impersonationSecondsLeft() {
    if (!isImpersonating()) {
        return 0;
    }
    $started = (int) ($_SESSION['impersonation_started'] ?? 0);
    return max(0, $started + kbfImpersonationSeconds() - time());
}

// Second step when starting impersonation: when the Super Admin has 2-step
// verification on, a current code is required as well as the password.
// Returns null to allow, or an error message to refuse.
function verifyImpersonationSecondFactor($superAdminUserId, array $data) {
    try {
        $state = twoFactorUserState($superAdminUserId);
    } catch (PDOException $e) {
        error_log('verifyImpersonationSecondFactor: ' . $e->getMessage());
        return null; // two-step.sql not run yet: nobody has 2-step
    }
    if (!$state || !$state['enabled']) {
        return null;
    }
    if (kbfCleanTotpInput($data['code'] ?? null) === null) {
        return 'Please enter the 6-digit code from your authenticator app.';
    }
    if (kbfTwoStepTooManyFailures($superAdminUserId)) {
        auditLog('two_step_lockout', 'user', $superAdminUserId, 'Too many wrong codes in 15 minutes (impersonation)');
        return 'Too many wrong codes. Please try again in 15 minutes.';
    }
    if (!kbfVerifyAndConsumeTotp($superAdminUserId, $data['code'])) {
        kbfRecordTwoStepAttempt($superAdminUserId, false);
        return 'That code is not right. Please try the latest code from your app.';
    }
    kbfRecordTwoStepAttempt($superAdminUserId, true);
    return null;
}
?>
