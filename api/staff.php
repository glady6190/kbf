<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';

// ============================================
// STAFF AND ACCESS API (Super Admin only)
//
//   GET  ?action=list                 everyone with staff access
//   GET  ?action=get&id=              one person, their permissions and recent changes
//   GET  ?action=search&q=            members who are not yet staff (2+ chars, max 20)
//   POST {action:'grant'}             give an existing member access
//   POST {action:'create'}            create someone who does not play, with access
//   POST {action:'update'}            change labels and permission keys
//   POST {action:'setLogin'}          sign-in on or off (Users.LoginEnabled)
//   POST {action:'setSuperAdmin'}     make or remove Super Admin
//   POST {action:'passwordHelp'}      reset email, or a new temporary password
//   POST {action:'startImpersonation', userId, password}
//                                     view the site as another staff member
//   POST {action:'stopImpersonation'} return to the Super Admin's own account
//                                     (the only action allowed while impersonating)
//   GET  ?action=securitySettings     2-step policy and how many staff have set it up
//   POST {action:'setTwoStepPolicy', policy}          off | optional | required_admins | required_all
//   POST {action:'setTwoStepRequired', userId, required: bool}
//   POST {action:'resetTwoStep', userId}              clears their secret and backup codes
//
// Order of checks: logged in (401), CSRF on writes (403), Super Admin (403).
// Temporary passwords are returned ONCE in the response. They are never stored
// in plain text and never logged.
//
// Users.Role (the old single role) is not changed for existing accounts in
// this stage. A brand-new account gets a Role that matches its labels so the
// existing role checks treat it consistently.
// ============================================

$method = $_SERVER['REQUEST_METHOD'];

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}

if ($method === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}

// While impersonating, Stop is the only action allowed here
if (isImpersonating()) {
    $stopData = $method === 'POST' ? json_decode(file_get_contents('php://input'), true) : null;
    if (is_array($stopData) && ($stopData['action'] ?? '') === 'stopImpersonation') {
        $viewedUserId = endImpersonation('returned to own account');
        sendJSON([
            'success' => true,
            'message' => 'You are back on your own account',
            'redirectUrl' => '/admin/staff-person?id=' . (int) $viewedUserId
        ]);
    }
    requireNotImpersonating();
}

requireSuperAdmin();

const STAFF_ROLE_KEYS = ['MainAdmin', 'ZonalAdmin', 'Organiser'];

// ============================================
// HELPERS
// ============================================

// SQL condition: the user (alias u) is staff
function staffCondition() {
    return "(u.IsSuperAdmin = 1
             OR EXISTS (SELECT 1 FROM StaffRoles sr WHERE sr.UserId = u.UserId)
             OR EXISTS (SELECT 1 FROM UserPermissions up WHERE up.UserId = u.UserId))";
}

function isStaffUser($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT u.UserId FROM Users u WHERE u.UserId = ? AND " . staffCondition());
    $stmt->execute([$userId]);
    return (bool) $stmt->fetch();
}

function staffLabels($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT RoleKey FROM StaffRoles WHERE UserId = ? ORDER BY RoleKey");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function staffPermissionKeys($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT PermissionKey FROM UserPermissions WHERE UserId = ? ORDER BY PermissionKey");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Shape one Users row (with CategoryName / CategoryShortName) for the pages
function staffPersonRow($row, $labels) {
    return [
        'userId' => (int) $row['UserId'],
        'firstName' => $row['FirstName'],
        'lastName' => $row['LastName'],
        'kbfId' => $row['PlayerId'],
        'email' => $row['Email'],
        'location' => $row['Location'],
        'profilePhoto' => $row['ProfilePhoto'],
        'grade' => $row['CategoryShortName'] !== null ? $row['CategoryShortName'] : null,
        'gradeName' => $row['CategoryName'],
        'labels' => array_values($labels),
        'isSuperAdmin' => (int) $row['IsSuperAdmin'] === 1,
        'isActive' => (int) $row['IsActive'] === 1,
        'loginEnabled' => (int) $row['LoginEnabled'] === 1,
        // Active staff with at least one permission, not a Super Admin, not me
        'canImpersonate' => (int) $row['IsActive'] === 1
            && (int) $row['IsSuperAdmin'] !== 1
            && (int) $row['HasPermissions'] === 1
            && (int) $row['UserId'] !== (int) $_SESSION['user_id'],
        // 2-step: 'on', 'required' (must use it, not set up yet) or 'off'
        'twoStep' => staffTwoStepStatus($row, $labels),
        'twoStepRequired' => (int) $row['TwoFactorRequired'] === 1,
        'twoStepEnabledDate' => $row['TwoFactorEnabledDate'],
    ];
}

function staffTwoStepStatus($row, $labels) {
    static $policy = null;
    if ($policy === null) {
        $policy = twoFactorPolicy();
    }
    if (!empty($row['TwoFactorEnabledDate'])) {
        return 'on';
    }
    $mustUse = twoFactorMustUseFor($policy, (int) $row['IsSuperAdmin'] === 1, $labels, (int) $row['TwoFactorRequired'] === 1);
    return $mustUse ? 'required' : 'off';
}

const STAFF_PERSON_COLUMNS = "u.UserId, u.FirstName, u.LastName, u.PlayerId, u.Email, u.Location,
       u.ProfilePhoto, u.IsSuperAdmin, u.IsActive, u.LoginEnabled,
       u.TwoFactorEnabledDate, u.TwoFactorRequired,
       (CASE WHEN EXISTS (SELECT 1 FROM UserPermissions hp WHERE hp.UserId = u.UserId) THEN 1 ELSE 0 END) AS HasPermissions,
       c.Name AS CategoryName, c.ShortName AS CategoryShortName";

function loadStaffPerson($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT " . STAFF_PERSON_COLUMNS . ", u.PasswordHash
        FROM Users u
        LEFT JOIN Categories c ON u.CategoryId = c.CategoryId
        WHERE u.UserId = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

// Validate a labels array: subset of the three role keys
function readLabels($value) {
    if ($value === null) {
        return [[], null];
    }
    if (!is_array($value)) {
        return [null, 'Invalid roles'];
    }
    $labels = [];
    foreach ($value as $label) {
        if (!is_string($label) || !in_array($label, STAFF_ROLE_KEYS, true)) {
            return [null, 'Invalid role'];
        }
        $labels[$label] = true;
    }
    $labels = array_keys($labels);
    sort($labels);
    return [$labels, null];
}

// Sign-in rules (Security page): setting key => [label, minimum, maximum, today's value]
const STAFF_SIGN_IN_RULES = [
    'sessionMinutes' => ['session_timeout_minutes', 'Session ends after (minutes idle)', 15, 720, 120],
    'warningMinutes' => ['session_warning_minutes', 'Warning before it ends (minutes)', 1, 30, 5],
    'lockoutAttemptsEmail' => ['login_lockout_attempts_email', 'Failed sign-ins before lockout per email', 3, 20, 5],
    'lockoutAttemptsIp' => ['login_lockout_attempts_ip', 'Failed sign-ins before lockout per internet address', 10, 200, 20],
    'lockoutMinutes' => ['login_lockout_minutes', 'Lockout lasts (minutes)', 5, 120, 15],
    'impersonationMinutes' => ['impersonation_minutes', 'Impersonation ends after (minutes)', 5, 120, 30],
    'auditRetentionDays' => ['audit_log_retention_days', 'Keep the activity log for (days)', 90, 3650, 730],
];

// The sign-in rules in force now
function signInRulesNow() {
    $rules = [];
    foreach (STAFF_SIGN_IN_RULES as $field => $r) {
        $rules[$field] = kbfSettingInt($r[0], $r[4], $r[2], $r[3]);
    }
    return $rules;
}

// staff_access_changed notification after a change of labels or permissions
// (sent after the commit; never includes passwords)
function notifyStaffAccessChanged($pdo, $userId, array $labels, array $keys) {
    try {
        require_once __DIR__ . '/notify.php';
        $names = [];
        $roleNames = ['MainAdmin' => 'Main Admin', 'ZonalAdmin' => 'Zonal Admin', 'Organiser' => 'Organiser'];
        foreach ($labels as $label) {
            $names[] = $roleNames[$label] ?? $label;
        }
        if ($keys) {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT Label FROM Permissions WHERE PermissionKey IN ($placeholders) ORDER BY SortOrder");
            $stmt->execute(array_values($keys));
            $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        notify('staff_access_changed', [
            'person' => (int) $userId,
            'values' => ['access_list' => $names ? implode(', ', $names) : 'No admin access'],
            'relatedType' => 'user',
            'relatedId' => (int) $userId,
        ]);
    } catch (\Throwable $e) {
        error_log('staff.php: access notification error: ' . $e->getMessage());
    }
}

// Validate permission keys against the Permissions table
function readPermissionKeys($pdo, $value) {
    if ($value === null) {
        return [[], null];
    }
    if (!is_array($value)) {
        return [null, 'Invalid permissions'];
    }
    $keys = [];
    foreach ($value as $key) {
        if (!is_string($key) || strlen($key) > 60) {
            return [null, 'Invalid permission'];
        }
        $keys[$key] = true;
    }
    $keys = array_keys($keys);
    if (empty($keys)) {
        return [[], null];
    }
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT PermissionKey FROM Permissions WHERE PermissionKey IN ($placeholders)");
    $stmt->execute($keys);
    $known = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($keys as $key) {
        if (!in_array($key, $known, true)) {
            return [null, 'Unknown permission: ' . $key];
        }
    }
    sort($keys);
    return [$keys, null];
}

// Replace a user's labels and permission keys (call inside a transaction)
function writeAccess($pdo, $userId, $labels, $keys, $grantedBy) {
    $currentLabels = staffLabels($pdo, $userId);
    $currentKeys = staffPermissionKeys($pdo, $userId);

    $delLabel = $pdo->prepare("DELETE FROM StaffRoles WHERE UserId = ? AND RoleKey = ?");
    foreach (array_diff($currentLabels, $labels) as $label) {
        $delLabel->execute([$userId, $label]);
    }
    $addLabel = $pdo->prepare("INSERT IGNORE INTO StaffRoles (UserId, RoleKey) VALUES (?, ?)");
    foreach (array_diff($labels, $currentLabels) as $label) {
        $addLabel->execute([$userId, $label]);
    }

    $delKey = $pdo->prepare("DELETE FROM UserPermissions WHERE UserId = ? AND PermissionKey = ?");
    foreach (array_diff($currentKeys, $keys) as $key) {
        $delKey->execute([$userId, $key]);
    }
    $addKey = $pdo->prepare("
        INSERT IGNORE INTO UserPermissions (UserId, PermissionKey, GrantedByUserId, GrantedDate)
        VALUES (?, ?, ?, NOW())
    ");
    foreach (array_diff($keys, $currentKeys) as $key) {
        $addKey->execute([$userId, $key, $grantedBy]);
    }

    return [$currentLabels, $currentKeys];
}

// Human-readable before/after for the audit log
function describeAccessChange($beforeLabels, $afterLabels, $beforeKeys, $afterKeys) {
    $list = function ($items) {
        return empty($items) ? '(none)' : implode(', ', $items);
    };
    $parts = [];
    $parts[] = 'Roles: ' . $list($beforeLabels) . ' -> ' . $list($afterLabels);
    $parts[] = 'Permissions before: ' . $list($beforeKeys);
    $parts[] = 'Permissions after: ' . $list($afterKeys);
    $added = array_values(array_diff($afterKeys, $beforeKeys));
    $removed = array_values(array_diff($beforeKeys, $afterKeys));
    if ($added) {
        $parts[] = 'Added: ' . implode(', ', $added);
    }
    if ($removed) {
        $parts[] = 'Removed: ' . implode(', ', $removed);
    }
    return implode('; ', $parts);
}

// Random temporary password meeting the site's rules (upper, lower, digit, 8+)
function generateTemporaryPassword() {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digits = '23456789';
    $all = $upper . $lower . $digits;

    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];
    while (count($chars) < 12) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    // Fisher-Yates shuffle with a secure random source
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $chars[$i];
        $chars[$i] = $chars[$j];
        $chars[$j] = $tmp;
    }
    return implode('', $chars);
}

// Set a new temporary password and require a change at next sign-in.
// Returns the plain password for showing ONCE; it is never stored or logged.
function setTemporaryPassword($pdo, $userId) {
    $password = generateTemporaryPassword();
    $stmt = $pdo->prepare("UPDATE Users SET PasswordHash = ?, RequirePasswordChange = 1 WHERE UserId = ?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    return $password;
}

// Legacy Users.Role for a brand-new account, from its labels
function legacyRoleForLabels($labels) {
    if (in_array('MainAdmin', $labels, true)) {
        return 'MainAdmin';
    }
    if (in_array('ZonalAdmin', $labels, true)) {
        return 'ZonalAdmin';
    }
    if (in_array('Organiser', $labels, true)) {
        return 'ClubAdmin';
    }
    return 'Player';
}

function cleanLine($value, $maxLength) {
    $text = is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)) : '';
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

$actorId = (int) $_SESSION['user_id'];

// ============================================
// GET
// ============================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    try {
        // ---------- LIST ----------
        if ($action === 'list') {
            $stmt = $pdo->query("
                SELECT " . STAFF_PERSON_COLUMNS . "
                FROM Users u
                LEFT JOIN Categories c ON u.CategoryId = c.CategoryId
                WHERE " . staffCondition() . "
                ORDER BY u.FirstName, u.LastName
            ");
            $rows = $stmt->fetchAll();

            $labelsByUser = [];
            $stmt = $pdo->query("SELECT UserId, RoleKey FROM StaffRoles ORDER BY RoleKey");
            foreach ($stmt->fetchAll() as $r) {
                $labelsByUser[(int) $r['UserId']][] = $r['RoleKey'];
            }

            $staff = [];
            foreach ($rows as $row) {
                $staff[] = staffPersonRow($row, $labelsByUser[(int) $row['UserId']] ?? []);
            }

            sendJSON(['success' => true, 'currentUserId' => $actorId, 'staff' => $staff]);
        }

        // ---------- GET ONE ----------
        if ($action === 'get') {
            $userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId) {
                sendJSON(['success' => false, 'message' => 'Invalid user ID'], 400);
            }

            $row = loadStaffPerson($pdo, $userId);
            if (!$row) {
                sendJSON(['success' => false, 'message' => 'Person not found'], 404);
            }

            $person = staffPersonRow($row, staffLabels($pdo, $userId));
            $person['permissions'] = staffPermissionKeys($pdo, $userId);
            $person['hasPassword'] = !empty($row['PasswordHash']);
            $person['hasEmail'] = !empty($row['Email']);
            $person['isStaff'] = isStaffUser($pdo, $userId);

            $stmt = $pdo->prepare("
                SELECT a.AuditId, a.Action, a.Details, a.CreatedDate, a.ActorUserId,
                       actor.FirstName AS ActorFirstName, actor.LastName AS ActorLastName
                FROM AuditLog a
                LEFT JOIN Users actor ON a.ActorUserId = actor.UserId
                WHERE a.TargetType = 'user' AND a.TargetId = ?
                ORDER BY a.CreatedDate DESC, a.AuditId DESC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            $changes = [];
            foreach ($stmt->fetchAll() as $c) {
                $actorName = trim(($c['ActorFirstName'] ?? '') . ' ' . ($c['ActorLastName'] ?? ''));
                $changes[] = [
                    'action' => $c['Action'],
                    'details' => $c['Details'],
                    'date' => $c['CreatedDate'],
                    'actorName' => ((int) $c['ActorUserId'] === 0 || $actorName === '') ? 'System' : $actorName,
                ];
            }

            $stmt = $pdo->query("SELECT PermissionKey, Label, GroupName, SortOrder FROM Permissions ORDER BY SortOrder");

            sendJSON([
                'success' => true,
                'currentUserId' => $actorId,
                'person' => $person,
                'recentChanges' => $changes,
                'allPermissions' => $stmt->fetchAll(),
            ]);
        }

        // ---------- SEARCH MEMBERS ----------
        // ---------- SECURITY SETTINGS ----------
        if ($action === 'securitySettings') {
            $stmt = $pdo->query("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN u.TwoFactorEnabledDate IS NOT NULL THEN 1 ELSE 0 END) AS setUp
                FROM Users u
                WHERE u.IsActive = 1
                  AND (u.IsSuperAdmin = 1 OR EXISTS (SELECT 1 FROM UserPermissions up WHERE up.UserId = u.UserId))
            ");
            $counts = $stmt->fetch();
            sendJSON([
                'success' => true,
                'twoStepPolicy' => twoFactorPolicy(),
                'twoStepConfigured' => twoFactorConfigured(),
                'staffTotal' => (int) $counts['total'],
                'staffSetUp' => (int) $counts['setUp'],
                'signInRules' => signInRulesNow(),
            ]);
        }

        if ($action === 'search') {
            $q = cleanLine($_GET['q'] ?? '', 100);
            $qLength = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
            if ($qLength < 2) {
                sendJSON(['success' => false, 'message' => 'Enter at least 2 characters'], 400);
            }
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

            $stmt = $pdo->prepare("
                SELECT u.UserId, u.FirstName, u.LastName, u.Location, u.ProfilePhoto,
                       c.ShortName AS CategoryShortName
                FROM Users u
                LEFT JOIN Categories c ON u.CategoryId = c.CategoryId
                WHERE u.IsActive = 1
                  AND NOT " . staffCondition() . "
                  AND (CONCAT(u.FirstName, ' ', u.LastName) LIKE ?
                       OR u.PlayerId LIKE ?
                       OR u.Email LIKE ?
                       OR u.Location LIKE ?)
                ORDER BY u.FirstName, u.LastName
                LIMIT 20
            ");
            $stmt->execute([$like, $like, $like, $like]);

            $results = [];
            foreach ($stmt->fetchAll() as $r) {
                $results[] = [
                    'userId' => (int) $r['UserId'],
                    'firstName' => $r['FirstName'],
                    'lastName' => $r['LastName'],
                    'location' => $r['Location'],
                    'profilePhoto' => $r['ProfilePhoto'],
                    'grade' => $r['CategoryShortName'],
                ];
            }

            sendJSON(['success' => true, 'results' => $results]);
        }

        sendJSON(['success' => false, 'message' => 'Invalid action'], 400);

    } catch (PDOException $e) {
        error_log('Database error in staff.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST
// ============================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
    }
    $action = is_string($data['action'] ?? null) ? $data['action'] : '';

    try {
        // ---------- GRANT ACCESS TO AN EXISTING MEMBER ----------
        if ($action === 'grant') {
            $userId = filter_var($data['userId'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId) {
                sendJSON(['success' => false, 'message' => 'Invalid user ID'], 400);
            }
            list($labels, $error) = readLabels($data['labels'] ?? []);
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error], 400);
            }
            list($keys, $error) = readPermissionKeys($pdo, $data['permissions'] ?? []);
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error], 400);
            }
            if (empty($labels) && empty($keys)) {
                sendJSON(['success' => false, 'message' => 'Choose at least one role or permission'], 400);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT UserId, FirstName, LastName, PasswordHash, IsActive FROM Users WHERE UserId = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $member = $stmt->fetch();
            if (!$member || (int) $member['IsActive'] !== 1) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Member not found'], 404);
            }
            if (isStaffUser($pdo, $userId)) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'This person is already staff. Edit their access instead.'], 409);
            }

            writeAccess($pdo, $userId, $labels, $keys, $actorId);

            $temporaryPassword = null;
            if (empty($member['PasswordHash'])) {
                $temporaryPassword = setTemporaryPassword($pdo, $userId);
            }

            $pdo->commit();

            auditLog('staff_granted', 'user', $userId,
                describeAccessChange([], $labels, [], $keys)
                . ($temporaryPassword !== null ? '; temporary password set' : ''));

            notifyStaffAccessChanged($pdo, $userId, $labels, $keys);

            sendJSON([
                'success' => true,
                'message' => 'Access granted',
                'userId' => (int) $userId,
                'temporaryPassword' => $temporaryPassword,
            ]);
        }

        // ---------- CREATE SOMEONE WHO DOES NOT PLAY ----------
        if ($action === 'create') {
            $firstName = cleanLine($data['firstName'] ?? '', 100);
            $lastName = cleanLine($data['lastName'] ?? '', 100);
            $location = cleanLine($data['location'] ?? '', 100);
            $email = filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);

            if ($firstName === '' || $lastName === '' || $location === '') {
                sendJSON(['success' => false, 'message' => 'First name, last name and location are required'], 400);
            }
            if (!$email) {
                sendJSON(['success' => false, 'message' => 'A valid email is required'], 400);
            }
            $email = strtolower($email);

            list($labels, $error) = readLabels($data['labels'] ?? []);
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error], 400);
            }
            list($keys, $error) = readPermissionKeys($pdo, $data['permissions'] ?? []);
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error], 400);
            }
            if (empty($labels) && empty($keys)) {
                sendJSON(['success' => false, 'message' => 'Choose at least one role or permission'], 400);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT UserId FROM Users WHERE Email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Email already exists'], 409);
            }

            // No grade, no Player ID, no performance-tracker row
            $stmt = $pdo->prepare("
                INSERT INTO Users
                (PlayerId, Email, PasswordHash, FirstName, LastName, Location, PostCode,
                 Role, ClubId, CategoryId, TotalPoints, IsActive, CreatedDate)
                VALUES (NULL, ?, NULL, ?, ?, ?, '', ?, NULL, NULL, 0, 1, NOW())
            ");
            $stmt->execute([
                $email,
                sanitizeInput($firstName),
                sanitizeInput($lastName),
                sanitizeInput($location),
                legacyRoleForLabels($labels),
            ]);
            $userId = (int) $pdo->lastInsertId();

            writeAccess($pdo, $userId, $labels, $keys, $actorId);
            $temporaryPassword = setTemporaryPassword($pdo, $userId);

            $pdo->commit();

            auditLog('staff_created', 'user', $userId,
                'Created ' . $firstName . ' ' . $lastName . ' (does not play); '
                . describeAccessChange([], $labels, [], $keys) . '; temporary password set');

            notifyStaffAccessChanged($pdo, $userId, $labels, $keys);

            sendJSON([
                'success' => true,
                'message' => 'Staff member created',
                'userId' => $userId,
                'temporaryPassword' => $temporaryPassword,
            ]);
        }

        // ---------- 2-STEP POLICY ----------
        if ($action === 'setTwoStepPolicy') {
            $policy = $data['policy'] ?? null;
            if (!is_string($policy) || !in_array($policy, KBF_TWO_STEP_POLICIES, true)) {
                sendJSON(['success' => false, 'message' => 'Invalid policy'], 400);
            }
            $before = twoFactorPolicy();
            $stmt = $pdo->prepare("
                INSERT INTO Settings (SettingKey, SettingValue) VALUES ('two_factor_policy', ?)
                ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
            ");
            $stmt->execute([$policy]);
            if ($before !== $policy) {
                auditLog('two_step_policy_changed', 'setting', null, 'Policy: ' . $before . ' -> ' . $policy);
            }
            sendJSON(['success' => true, 'message' => 'Settings saved', 'twoStepPolicy' => $policy]);
        }

        // ---------- SIGN-IN RULES ----------
        // Super Admin only (checked above), CSRF checked, blocked while impersonating
        if ($action === 'setSignInRules') {
            $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
            $new = [];
            foreach (STAFF_SIGN_IN_RULES as $field => $r) {
                $value = $rules[$field] ?? null;
                if (is_string($value) && preg_match('/^\s*\d{1,4}\s*$/', $value)) {
                    $value = (int) trim($value);
                }
                if (!is_int($value) || $value < $r[2] || $value > $r[3]) {
                    sendJSON(['success' => false, 'message' => $r[1] . ' must be from ' . $r[2] . ' to ' . $r[3] . '.', 'field' => $field], 400);
                }
                $new[$field] = $value;
            }
            if ($new['warningMinutes'] >= $new['sessionMinutes']) {
                sendJSON(['success' => false, 'message' => 'The warning must come before the session ends: choose fewer minutes than the session length.', 'field' => 'warningMinutes'], 400);
            }

            $old = signInRulesNow();
            $stmt = $pdo->prepare("
                INSERT INTO Settings (SettingKey, SettingValue) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
            ");
            $changed = [];
            foreach (STAFF_SIGN_IN_RULES as $field => $r) {
                if ($old[$field] !== $new[$field]) {
                    $stmt->execute([$r[0], (string) $new[$field]]);
                    $changed[] = $r[0] . ': ' . $old[$field] . ' -> ' . $new[$field];
                }
            }
            foreach ($changed as $line) {
                auditLog('setting_changed', 'setting', null, $line);
            }
            kbfSettingsForget();
            sendJSON([
                'success' => true,
                'message' => $changed ? 'Sign-in rules saved' : 'Nothing to change',
                'signInRules' => signInRulesNow(),
            ]);
        }

        // Every remaining action works on an existing user
        $userId = filter_var($data['userId'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId) {
            sendJSON(['success' => false, 'message' => 'Invalid user ID'], 400);
        }

        // ---------- UPDATE ACCESS ----------
        if ($action === 'update') {
            list($labels, $error) = readLabels($data['labels'] ?? []);
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error], 400);
            }
            list($keys, $error) = readPermissionKeys($pdo, $data['permissions'] ?? []);
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error], 400);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT UserId FROM Users WHERE UserId = ? FOR UPDATE");
            $stmt->execute([$userId]);
            if (!$stmt->fetch()) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Person not found'], 404);
            }

            list($beforeLabels, $beforeKeys) = writeAccess($pdo, $userId, $labels, $keys, $actorId);
            sort($beforeLabels);
            sort($beforeKeys);

            $pdo->commit();

            if ($beforeLabels != $labels || $beforeKeys != $keys) {
                auditLog('access_updated', 'user', $userId,
                    describeAccessChange($beforeLabels, $labels, $beforeKeys, $keys));
                notifyStaffAccessChanged($pdo, $userId, $labels, $keys);
            }

            sendJSON(['success' => true, 'message' => 'Access saved']);
        }

        // ---------- SIGN-IN ON OR OFF ----------
        if ($action === 'setLogin') {
            if (!array_key_exists('enabled', $data) || !is_bool($data['enabled'])) {
                sendJSON(['success' => false, 'message' => 'Enabled must be true or false'], 400);
            }
            $enabled = $data['enabled'];
            if (!$enabled && $userId === $actorId) {
                sendJSON(['success' => false, 'message' => 'You cannot turn off your own sign-in'], 400);
            }

            $stmt = $pdo->prepare("SELECT LoginEnabled FROM Users WHERE UserId = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJSON(['success' => false, 'message' => 'Person not found'], 404);
            }

            if ((int) $row['LoginEnabled'] !== ($enabled ? 1 : 0)) {
                $stmt = $pdo->prepare("UPDATE Users SET LoginEnabled = ? WHERE UserId = ?");
                $stmt->execute([$enabled ? 1 : 0, $userId]);
                auditLog($enabled ? 'login_enabled' : 'login_disabled', 'user', $userId,
                    'Sign-in: ' . ($enabled ? 'off -> on' : 'on -> off'));
            }

            sendJSON(['success' => true, 'message' => $enabled ? 'Sign-in turned on' : 'Sign-in turned off']);
        }

        // ---------- MAKE OR REMOVE SUPER ADMIN ----------
        if ($action === 'setSuperAdmin') {
            if (!array_key_exists('superAdmin', $data) || !is_bool($data['superAdmin'])) {
                sendJSON(['success' => false, 'message' => 'superAdmin must be true or false'], 400);
            }
            $makeSuper = $data['superAdmin'];

            if (!$makeSuper && $userId === $actorId) {
                sendJSON(['success' => false, 'message' => 'You cannot remove your own Super Admin status'], 400);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT IsSuperAdmin, IsActive FROM Users WHERE UserId = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Person not found'], 404);
            }
            if ($makeSuper && (int) $row['IsActive'] !== 1) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Only active accounts can be made Super Admin'], 400);
            }

            if ((int) $row['IsSuperAdmin'] === ($makeSuper ? 1 : 0)) {
                $pdo->rollBack();
                sendJSON(['success' => true, 'message' => 'No change']);
            }

            if (!$makeSuper) {
                // There must always be at least one active Super Admin
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS cnt FROM Users
                    WHERE IsSuperAdmin = 1 AND IsActive = 1 AND UserId <> ?
                    FOR UPDATE
                ");
                $stmt->execute([$userId]);
                if ((int) $stmt->fetch()['cnt'] < 1) {
                    $pdo->rollBack();
                    sendJSON(['success' => false, 'message' => 'There must always be at least one active Super Admin'], 400);
                }
            }

            $stmt = $pdo->prepare("UPDATE Users SET IsSuperAdmin = ? WHERE UserId = ?");
            $stmt->execute([$makeSuper ? 1 : 0, $userId]);

            $pdo->commit();

            auditLog($makeSuper ? 'superadmin_granted' : 'superadmin_removed', 'user', $userId,
                'Super Admin: ' . ($makeSuper ? 'no -> yes' : 'yes -> no'));

            sendJSON(['success' => true, 'message' => $makeSuper ? 'Now a Super Admin' : 'Super Admin removed']);
        }

        // ---------- PASSWORD HELP ----------
        if ($action === 'passwordHelp') {
            $stmt = $pdo->prepare("SELECT UserId, Email, FirstName, LastName, PasswordHash, IsActive FROM Users WHERE UserId = ?");
            $stmt->execute([$userId]);
            $person = $stmt->fetch();
            if (!$person || (int) $person['IsActive'] !== 1) {
                sendJSON(['success' => false, 'message' => 'Person not found'], 404);
            }

            if (!empty($person['PasswordHash']) && !empty($person['Email'])) {
                // Normal password reset email (same token handling as forgot-password.php)
                require_once __DIR__ . '/notify.php';

                $stmt = $pdo->prepare("DELETE FROM PasswordResets WHERE UserId = ? AND Used = 0");
                $stmt->execute([$userId]);

                $token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("
                    INSERT INTO PasswordResets (UserId, Email, Token, ExpiresAt, IpAddress, CreatedAt)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $userId,
                    $person['Email'],
                    hash('sha256', $token),
                    date('Y-m-d H:i:s', strtotime('+1 hour')),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Locked event: always on. The link is never stored (secrets).
                $results = notify('password_reset_requested', [
                    'person' => (int) $userId,
                    'values' => [
                        'reset_link' => kbfSiteLink($pdo) . '/reset-password.html?token=' . urlencode($token),
                    ],
                    'secrets' => ['reset_link'],
                    'relatedType' => 'user',
                    'relatedId' => (int) $userId,
                ]);
                if (($results['person'] ?? '') !== 'sent') {
                    // Reset emails never wait (the link is not stored), so this is a failure
                    error_log('staff.php: password reset email failed for user ' . (int) $userId);
                    $reason = rtrim(trim((string) ($results['errors']['person'] ?? $results['errors']['notify'] ?? '')), '.');
                    sendJSON([
                        'success' => false,
                        'message' => 'The reset email could not be sent' . ($reason !== '' ? ': ' . $reason : '') . '.',
                    ], 500);
                }

                auditLog('password_reset_sent', 'user', $userId, 'Password reset email sent by a Super Admin');

                sendJSON(['success' => true, 'mode' => 'email', 'message' => 'Password reset email sent']);
            }

            $temporaryPassword = setTemporaryPassword($pdo, $userId);
            auditLog('temporary_password_set', 'user', $userId, 'Temporary password set by a Super Admin');

            sendJSON([
                'success' => true,
                'mode' => 'temporary',
                'message' => 'Temporary password set',
                'temporaryPassword' => $temporaryPassword,
            ]);
        }

        // ---------- 2-STEP: REQUIRE FOR THIS PERSON ----------
        if ($action === 'setTwoStepRequired') {
            $targetId = filter_var($data['userId'] ?? null, FILTER_VALIDATE_INT);
            if (!$targetId || !isset($data['required']) || !is_bool($data['required'])) {
                sendJSON(['success' => false, 'message' => 'User ID and required (true or false) needed'], 400);
            }
            if (!isStaffUser($pdo, $targetId)) {
                sendJSON(['success' => false, 'message' => 'Person not found'], 404);
            }
            $stmt = $pdo->prepare("UPDATE Users SET TwoFactorRequired = ? WHERE UserId = ?");
            $stmt->execute([$data['required'] ? 1 : 0, $targetId]);
            auditLog('two_step_requirement_changed', 'user', $targetId,
                $data['required'] ? '2-step verification required for this person' : '2-step verification no longer required for this person');
            sendJSON(['success' => true, 'message' => $data['required'] ? 'Now required' : 'No longer required']);
        }

        // ---------- 2-STEP: RESET ----------
        if ($action === 'resetTwoStep') {
            $targetId = filter_var($data['userId'] ?? null, FILTER_VALIDATE_INT);
            if (!$targetId) {
                sendJSON(['success' => false, 'message' => 'Invalid user ID'], 400);
            }
            if (!isStaffUser($pdo, $targetId)) {
                sendJSON(['success' => false, 'message' => 'Person not found'], 404);
            }
            $pdo->beginTransaction();
            kbfClearTwoFactor($targetId);
            $pdo->commit();
            auditLog('two_step_reset', 'user', $targetId, '2-step verification reset by a Super Admin; secret and backup codes cleared');
            sendJSON(['success' => true, 'message' => '2-step verification has been reset']);
        }

        // ---------- START IMPERSONATION ----------
        // Super Admin (checked above), CSRF (checked above), then the Super
        // Admin's own current password with the same failed-attempt limits as
        // sign-in, then the second step (verifyImpersonationSecondFactor).
        if ($action === 'startImpersonation') {
            $targetUserId = filter_var($data['userId'] ?? null, FILTER_VALIDATE_INT);
            if (!$targetUserId) {
                sendJSON(['success' => false, 'message' => 'Invalid user ID'], 400);
            }
            $password = is_string($data['password'] ?? null) ? $data['password'] : '';
            if ($password === '') {
                sendJSON(['success' => false, 'message' => 'Please enter your password'], 400);
            }

            $superAdminId = (int) $_SESSION['user_id'];
            $target = loadImpersonationTarget($targetUserId, $superAdminId);
            if (!$target) {
                sendJSON(['success' => false, 'message' => 'This person cannot be impersonated'], 403);
            }

            $stmt = $pdo->prepare("SELECT Email, PasswordHash FROM Users WHERE UserId = ?");
            $stmt->execute([$superAdminId]);
            $self = $stmt->fetch();
            $selfEmail = $self ? (string) $self['Email'] : '';

            if (!checkLoginAttempts($selfEmail, $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
                sendJSON(['success' => false, 'message' => 'Too many failed attempts. Please try again in 15 minutes.'], 429);
            }

            if (!$self || empty($self['PasswordHash']) || !password_verify($password, $self['PasswordHash'])) {
                recordLoginAttempt($selfEmail, false);
                auditLog('impersonation_refused', 'user', $targetUserId, 'Wrong password');
                sendJSON(['success' => false, 'message' => 'Incorrect password'], 401);
            }

            $secondFactorError = verifyImpersonationSecondFactor($superAdminId, $data);
            if ($secondFactorError !== null) {
                sendJSON(['success' => false, 'message' => $secondFactorError], 401);
            }

            recordLoginAttempt($selfEmail, true);
            startImpersonation($target);

            sendJSON([
                'success' => true,
                'message' => 'You are now viewing the site as ' . trim($target['FirstName'] . ' ' . $target['LastName']),
                'redirectUrl' => '/admin/dashboard'
            ]);
        }

        sendJSON(['success' => false, 'message' => 'Invalid action'], 400);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Database error in staff.php POST: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
?>
