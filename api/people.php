<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/report-helpers.php';

// ============================================
// PEOPLE API (players and staff in one place)
//
//   GET  ?action=list        tab, grade, q, sort, page    the People list (50 a page)
//   GET  ?action=person      id                           one person's details
//   GET  ?action=results     id                           points history, newest first
//   GET  ?action=grades      id                           grade changes, newest first
//   GET  ?action=activity    id, view=about|by, page      activity (50 a page)
//   POST {action:'add'}                                    add a player
//   POST {action:'edit'}                                   edit details (never the grade)
//   POST {action:'setActive', id, active}                  deactivate or reactivate
//   POST {action:'remove', id, confirm: true}              remove at their request
//
// Order of checks: logged in (401), CSRF on writes (403), permission (403).
//   Reading:     players.view_export, players.edit_details or a Super Admin
//   Add:         players.approve_registrations AND players.edit_details
//   Edit:        players.edit_details (a staff member: Super Admin);
//                email and mobile also need players.contact_details
//   Deactivate:  players.edit_details AND players.change_grades
//                (a staff member: Super Admin; never yourself)
//   Remove:      Super Admin only, never staff, never yourself
// Email and mobile are returned only with players.contact_details. Staff
// access details (labels, permissions, sign-in, 2-step) only to Super Admins.
// Access changes, impersonation and Super Admin actions stay in api/staff.php.
// Every change is written to the activity log with old and new values,
// never with contact details.
// ============================================

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
} elseif ($method !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

$myId = (int) $_SESSION['user_id'];
$isSuper = isSuperAdmin();
$canView = $isSuper || hasAnyPermission(['players.view_export', 'players.edit_details']);
$canContacts = hasPermission('players.contact_details');

const PEOPLE_PAGE_SIZE = 50;
// Staff: a Super Admin or at least one permission (Users aliased "u")
const PEOPLE_STAFF_SQL = "(u.IsSuperAdmin = 1 OR EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId))";
// Someone the People page shows: anyone not removed. That includes people
// who neither play nor have staff access (for example a non-playing staff
// member whose access was removed), so they can be given access again or
// deactivated.
const PEOPLE_BASE_SQL = "u.IsRemoved = 0";
const PEOPLE_PHOTO_PATTERN = '#^/uploads/(profiles|registrations)/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#';
const PEOPLE_LABEL_ORDER = ['MainAdmin' => 'Main Admin', 'ZonalAdmin' => 'Zonal Admin', 'Organiser' => 'Organiser'];

// ============================================
// HELPERS
// ============================================
function pRows($pdo, $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function pValue($pdo, $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// Stored text was HTML-escaped on the way in; send plain text
function pText($value) {
    return $value === null ? null : trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
}

function pName($first, $last) {
    return trim(pText($first) . ' ' . pText($last));
}

function pIso($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? null : date('c', $ts);
}

function pPage() {
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    return $page ?: 1;
}

function pIdParam($value) {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        sendJSON(['success' => false, 'message' => 'Invalid person'], 400);
    }
    return (int) $id;
}

// Main staff label: Super Admin, Main Admin, Zonal Admin or Organiser (first that applies)
function pMainLabel($isSuper, $roles) {
    if ((int) $isSuper === 1) {
        return 'Super Admin';
    }
    $have = array_filter(explode(',', (string) $roles));
    foreach (PEOPLE_LABEL_ORDER as $key => $label) {
        if (in_array($key, $have, true)) {
            return $label;
        }
    }
    return null;
}

// One person the People page can show (not removed), or null
function pLoadPerson($pdo, $id) {
    $rows = pRows($pdo, "
        SELECT u.*, c.ShortName AS GradeShort, c.Name AS GradeName,
               pt.CareerPoints, pt.FinalsReached,
               (CASE WHEN " . PEOPLE_STAFF_SQL . " THEN 1 ELSE 0 END) AS IsStaffMember
        FROM Users u
        LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
        LEFT JOIN PlayerPerformanceTracker pt ON pt.PlayerId = u.UserId
        WHERE u.UserId = ? AND " . PEOPLE_BASE_SQL, [$id]);
    return $rows ? $rows[0] : null;
}

function pRequirePerson($pdo, $id) {
    $person = pLoadPerson($pdo, $id);
    if (!$person) {
        sendJSON(['success' => false, 'message' => 'Person not found'], 404);
    }
    return $person;
}

// Registration ids that belong to a person (by KBF ID)
function pRegistrationIds($pdo, $kbfId) {
    if ($kbfId === null || $kbfId === '') {
        return [];
    }
    return array_map('intval', array_column(
        pRows($pdo, "SELECT RegistrationId FROM PlayerRegistrations WHERE PlayerId = ?", [$kbfId]), 'RegistrationId'));
}

function pDeletePhotoFile($path) {
    if (!is_string($path) || !preg_match(PEOPLE_PHOTO_PATTERN, $path)) {
        return;
    }
    $full = dirname(__DIR__) . $path;
    if (is_file($full)) {
        @unlink($full);
    }
    // Its display version and thumbnail go too
    require_once __DIR__ . '/image-helpers.php';
    kbfDeleteImageVariants($full);
}

// Validation of the fields shared by Add and Edit. Returns [value, error].
// Text is stored as typed (never HTML-encoded) and escaped when shown.
function pCleanName($value, $what) {
    $text = kbfCleanText($value);
    if (mb_strlen($text) < 2 || mb_strlen($text) > 50) {
        return [null, $what . ' must be 2 to 50 characters'];
    }
    if (!kbfIsValidName($text)) {
        return [null, $what . ' can only contain letters (accents are fine), spaces, hyphens and apostrophes'];
    }
    return [$text, null];
}

function pCleanCity($value) {
    $text = kbfCleanText($value);
    if (mb_strlen($text) < 2 || mb_strlen($text) > 100) {
        return [null, 'City must be 2 to 100 characters'];
    }
    return [$text, null];
}

function pCleanPostCode($value) {
    $text = kbfCleanText($value);
    if ($text === '') {
        return [null, null];
    }
    if (mb_strlen($text) > 12) {
        return [null, 'Post code is too long'];
    }
    return [mb_strtoupper($text, 'UTF-8'), null];
}

function pCleanYear($value) {
    if ($value === null || trim((string) $value) === '') {
        return [null, null];
    }
    $year = filter_var($value, FILTER_VALIDATE_INT);
    $latest = kbfLatestBirthYear();
    if (!$year || $year < 1940 || $year > $latest) {
        return [null, 'Year of birth must be between 1940 and ' . $latest];
    }
    return [(int) $year, null];
}

function pCleanEmail($value) {
    $email = filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL);
    if (!$email || strlen($email) > 255) {
        return [null, 'Enter a valid email address'];
    }
    return [$email, null];
}

// International format (+ and digits), as on the registration form
function pCleanMobile($value) {
    $clean = preg_replace('/[\s\-\(\)]/', '', trim((string) $value));
    if (!preg_match('/^\+/', $clean)) {
        return [null, 'Enter the mobile number with its country code, for example +44 7700 900123'];
    }
    $clean = preg_replace('/^(\+\d{1,4})0+/', '$1', $clean);
    if (!preg_match('/^\+\d+$/', $clean)) {
        return [null, 'Enter the mobile number with its country code, for example +44 7700 900123'];
    }
    $digits = strlen($clean) - 1;
    if ($digits < 8 || $digits > 15) {
        return [null, 'The mobile number must have 8 to 15 digits'];
    }
    return [$clean, null];
}

function pCleanPhoto($value) {
    if ($value === null || $value === '') {
        return [null, null];
    }
    if (!is_string($value) || !preg_match(PEOPLE_PHOTO_PATTERN, $value)) {
        return [null, 'Invalid photo'];
    }
    return [$value, null];
}

function pActiveGradeIds($pdo) {
    return array_map(function ($g) { return (int) $g['CategoryId']; }, kbfActiveGrades($pdo));
}

// ============================================
// GET
// ============================================
if ($method === 'GET') {
    if (!$canView) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
    $action = $_GET['action'] ?? 'list';

    try {
        // ---------- LIST ----------
        if ($action === 'list') {
            $tabs = [
                'everyone' => 'u.IsActive = 1',
                'players' => 'u.IsActive = 1 AND u.CategoryId IS NOT NULL',
                'staff' => 'u.IsActive = 1 AND ' . PEOPLE_STAFF_SQL,
                'deactivated' => 'u.IsActive = 0',
            ];
            $tab = $_GET['tab'] ?? 'everyone';
            if (!isset($tabs[$tab])) {
                $tab = 'everyone';
            }

            $countRow = pRows($pdo, "
                SELECT SUM(CASE WHEN {$tabs['everyone']} THEN 1 ELSE 0 END) AS everyone,
                       SUM(CASE WHEN {$tabs['players']} THEN 1 ELSE 0 END) AS players,
                       SUM(CASE WHEN {$tabs['staff']} THEN 1 ELSE 0 END) AS staff,
                       SUM(CASE WHEN {$tabs['deactivated']} THEN 1 ELSE 0 END) AS deactivated
                FROM Users u
                WHERE " . PEOPLE_BASE_SQL)[0];

            $where = [PEOPLE_BASE_SQL, $tabs[$tab]];
            $params = [];

            $grades = kbfActiveGrades($pdo);
            $grade = filter_var($_GET['grade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($grade && in_array((int) $grade, pActiveGradeIds($pdo), true)) {
                $where[] = 'u.CategoryId = ?';
                $params[] = (int) $grade;
            } else {
                $grade = null;
            }

            // Search: name, KBF ID and city; email for people who may see emails.
            // Names and cities are stored as typed; older rows may still be
            // HTML-escaped (until jobs/decode-stored-text.php has run), so the
            // escaped form of the term is matched too.
            $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
            if ($q !== '') {
                $q = mb_substr($q, 0, 100);
                $stored = '%' . kbfLikeEscape(htmlspecialchars($q, ENT_QUOTES, 'UTF-8')) . '%';
                $raw = '%' . kbfLikeEscape($q) . '%';
                $parts = ['u.FirstName LIKE ?', 'u.LastName LIKE ?', "CONCAT(u.FirstName, ' ', u.LastName) LIKE ?",
                          'u.PlayerId LIKE ?', 'u.Location LIKE ?'];
                array_push($params, $raw, $raw, $raw, $raw, $raw);
                if ($stored !== $raw) {
                    array_push($parts, 'u.FirstName LIKE ?', 'u.LastName LIKE ?', "CONCAT(u.FirstName, ' ', u.LastName) LIKE ?",
                               'u.Location LIKE ?');
                    array_push($params, $stored, $stored, $stored, $stored);
                }
                if ($canContacts) {
                    $parts[] = 'u.Email LIKE ?';
                    $params[] = $raw;
                }
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }

            $sorts = [
                'grade' => 'c.DisplayOrder IS NULL, c.DisplayOrder ASC, u.TotalPoints DESC, u.FirstName ASC, u.LastName ASC',
                'name' => 'u.FirstName ASC, u.LastName ASC',
                'kbfId' => 'u.PlayerId IS NULL, CAST(SUBSTRING(u.PlayerId, 6) AS UNSIGNED) ASC, u.PlayerId ASC',
                'newest' => 'u.CreatedDate DESC, u.UserId DESC',
            ];
            $sort = $_GET['sort'] ?? 'grade';
            if (!isset($sorts[$sort])) {
                $sort = 'grade';
            }

            $from = "Users u
                LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
                LEFT JOIN PlayerPerformanceTracker pt ON pt.PlayerId = u.UserId";
            $whereSql = implode(' AND ', $where);
            $total = (int) pValue($pdo, "SELECT COUNT(*) FROM $from WHERE $whereSql", $params);

            $page = pPage();
            $offset = ($page - 1) * PEOPLE_PAGE_SIZE;
            $rows = pRows($pdo, "
                SELECT u.UserId, u.PlayerId, u.FirstName, u.LastName, u.Location, u.ProfilePhoto,
                       c.ShortName, u.TotalPoints, pt.CareerPoints, u.IsActive, u.IsSuperAdmin,
                       (CASE WHEN " . PEOPLE_STAFF_SQL . " THEN 1 ELSE 0 END) AS IsStaffMember,
                       (SELECT GROUP_CONCAT(sr.RoleKey) FROM StaffRoles sr WHERE sr.UserId = u.UserId) AS Roles"
                       . ($canContacts ? ", u.Email, u.PhoneNumber" : "") . "
                FROM $from
                WHERE $whereSql
                ORDER BY {$sorts[$sort]}
                LIMIT " . PEOPLE_PAGE_SIZE . " OFFSET " . (int) $offset, $params);

            $people = array_map(function ($r) use ($canContacts) {
                $row = [
                    'id' => (int) $r['UserId'],
                    'kbfId' => $r['PlayerId'],
                    'name' => pName($r['FirstName'], $r['LastName']),
                    'city' => pText($r['Location']),
                    'photo' => (is_string($r['ProfilePhoto']) && preg_match(PEOPLE_PHOTO_PATTERN, $r['ProfilePhoto'])) ? $r['ProfilePhoto'] : null,
                    'grade' => $r['ShortName'] !== null ? pText($r['ShortName']) : null,
                    'seasonPoints' => (int) $r['TotalPoints'],
                    'careerPoints' => (int) $r['CareerPoints'],
                    'active' => (int) $r['IsActive'] === 1,
                    'isStaff' => (int) $r['IsStaffMember'] === 1,
                    'staffLabel' => pMainLabel($r['IsSuperAdmin'], $r['Roles']),
                    // Neither plays nor has staff access
                    'noAccess' => (int) $r['IsStaffMember'] !== 1 && $r['ShortName'] === null,
                ];
                if ($canContacts) {
                    $row['email'] = $r['Email'];
                    $row['phone'] = pText($r['PhoneNumber']);
                }
                return $row;
            }, $rows);

            // Grade for new players: default_grade_id, or the lowest-ranked
            // active grade when that is missing or retired
            $defaultGrade = null;
            $defaultId = kbfDefaultGradeId($pdo);
            foreach ($grades as $g) {
                if ((int) $g['CategoryId'] === (int) $defaultId) {
                    $defaultGrade = ['id' => (int) $g['CategoryId'], 'shortName' => pText($g['ShortName']), 'name' => pText($g['Name'])];
                }
            }

            sendJSON([
                'success' => true,
                'tab' => $tab,
                'sort' => $sort,
                'grade' => $grade ? (int) $grade : null,
                'q' => $q,
                'counts' => [
                    'everyone' => (int) $countRow['everyone'],
                    'players' => (int) $countRow['players'],
                    'staff' => (int) $countRow['staff'],
                    'deactivated' => (int) $countRow['deactivated'],
                ],
                'grades' => array_map(function ($g) {
                    return ['id' => (int) $g['CategoryId'], 'shortName' => pText($g['ShortName']), 'name' => pText($g['Name'])];
                }, $grades),
                'defaultGrade' => $defaultGrade,
                'page' => $page,
                'pageSize' => PEOPLE_PAGE_SIZE,
                'total' => $total,
                'people' => $people,
            ]);
        }

        $id = pIdParam($_GET['id'] ?? null);

        // ---------- ONE PERSON ----------
        if ($action === 'person') {
            $p = pRequirePerson($pdo, $id);
            $isStaffMember = (int) $p['IsStaffMember'] === 1;
            $roles = pRows($pdo, "SELECT RoleKey FROM StaffRoles WHERE UserId = ? ORDER BY RoleKey", [$id]);
            $roleKeys = array_column($roles, 'RoleKey');

            $person = [
                'id' => (int) $p['UserId'],
                'kbfId' => $p['PlayerId'],
                'firstName' => pText($p['FirstName']),
                'lastName' => pText($p['LastName']),
                'name' => pName($p['FirstName'], $p['LastName']),
                'city' => pText($p['Location']),
                'postCode' => pText($p['PostCode']),
                'yearOfBirth' => $p['YearOfBirth'] !== null ? (int) $p['YearOfBirth'] : null,
                'photo' => (is_string($p['ProfilePhoto']) && preg_match(PEOPLE_PHOTO_PATTERN, $p['ProfilePhoto'])) ? $p['ProfilePhoto'] : null,
                'gradeId' => $p['CategoryId'] !== null ? (int) $p['CategoryId'] : null,
                'grade' => $p['GradeShort'] !== null ? pText($p['GradeShort']) : null,
                'gradeName' => $p['GradeName'] !== null ? pText($p['GradeName']) : null,
                'seasonPoints' => (int) $p['TotalPoints'],
                'careerPoints' => (int) $p['CareerPoints'],
                'finalsReached' => (int) $p['FinalsReached'],
                'active' => (int) $p['IsActive'] === 1,
                'joined' => pIso($p['CreatedDate']),
                'termsVersion' => $p['TermsVersion'] ?? null,
                'termsAcceptedDate' => pIso($p['TermsAcceptedDate'] ?? null),
                'isStaff' => $isStaffMember,
                'staffLabel' => pMainLabel($p['IsSuperAdmin'], implode(',', $roleKeys)),
                'isSelf' => (int) $p['UserId'] === $myId,
            ];
            if ($canContacts) {
                $person['email'] = $p['Email'];
                $person['phone'] = pText($p['PhoneNumber']);
            }

            // What the signed-in person may do here (the server checks again)
            $person['can'] = [
                'edit' => hasPermission('players.edit_details') && (!$isStaffMember || $isSuper),
                'editContacts' => hasPermission('players.edit_details') && $canContacts && (!$isStaffMember || $isSuper),
                'setActive' => !$person['isSelf'] && ($isStaffMember
                    ? $isSuper
                    : (hasPermission('players.edit_details') && hasPermission('players.change_grades'))),
                'changeGrade' => hasPermission('players.change_grades') && $p['CategoryId'] !== null,
                'remove' => $isSuper && !$isStaffMember && !$person['isSelf'],
                'access' => $isSuper,
                'activityBy' => $isSuper && $isStaffMember,
            ];

            // Staff access details: Super Admins only
            if ($isSuper) {
                $permissions = pRows($pdo, "
                    SELECT up.PermissionKey, p.Label
                    FROM UserPermissions up
                    LEFT JOIN Permissions p ON p.PermissionKey = up.PermissionKey
                    WHERE up.UserId = ?
                    ORDER BY p.SortOrder, up.PermissionKey", [$id]);
                $canSignIn = (bool) pValue($pdo, "SELECT COUNT(*) FROM Users u WHERE u.UserId = ? AND " . KBF_CAN_SIGN_IN_SQL, [$id]);
                $twoStepStatus = 'off';
                if (!empty($p['TwoFactorEnabledDate'])) {
                    $twoStepStatus = 'on';
                } elseif ($isStaffMember && twoFactorMustUseFor(twoFactorPolicy(), (int) $p['IsSuperAdmin'] === 1, $roleKeys, (int) ($p['TwoFactorRequired'] ?? 0) === 1)) {
                    $twoStepStatus = 'required';
                }
                $person['access'] = [
                    'isSuperAdmin' => (int) $p['IsSuperAdmin'] === 1,
                    'labels' => array_values(array_map(function ($k) { return PEOPLE_LABEL_ORDER[$k] ?? $k; }, $roleKeys)),
                    'labelKeys' => $roleKeys,
                    'permissions' => array_map(function ($r) {
                        return ['key' => $r['PermissionKey'], 'label' => $r['Label'] !== null ? pText($r['Label']) : $r['PermissionKey']];
                    }, $permissions),
                    'signIn' => [
                        'canSignIn' => $canSignIn,
                        'loginEnabled' => (int) ($p['LoginEnabled'] ?? 0) === 1,
                        'hasPassword' => !empty($p['PasswordHash']),
                        'lastSignIn' => pIso($p['LastLoginDate'] ?? null),
                    ],
                    'twoStep' => [
                        'status' => $twoStepStatus,
                        'enabledDate' => pIso($p['TwoFactorEnabledDate'] ?? null),
                        'required' => (int) ($p['TwoFactorRequired'] ?? 0) === 1,
                    ],
                ];
            }

            sendJSON(['success' => true, 'person' => $person]);
        }

        // ---------- RESULTS ----------
        if ($action === 'results') {
            pRequirePerson($pdo, $id);
            require_once __DIR__ . '/points-rules.php';
            $seasonFlag = kbfHasSeasonFlag($pdo) ? 'pph.CountsTowardSeason' : '1';
            $rows = pRows($pdo, "
                SELECT pph.AwardedDate, pph.TournamentId, t.Name AS TournamentName, pph.Reason, pph.PointsAwarded,
                       $seasonFlag AS CountsTowardSeason
                FROM PlayerPointsHistory pph
                LEFT JOIN Tournaments t ON t.TournamentId = pph.TournamentId
                WHERE pph.PlayerId = ?
                ORDER BY pph.AwardedDate DESC, pph.TournamentId DESC
                LIMIT 1000", [$id]);
            sendJSON(['success' => true, 'results' => array_map(function ($r) {
                return [
                    'date' => pIso($r['AwardedDate']),
                    'tournamentId' => $r['TournamentId'] !== null ? (int) $r['TournamentId'] : null,
                    'tournament' => pText($r['TournamentName']),
                    'placing' => pText($r['Reason']),
                    'points' => (int) $r['PointsAwarded'],
                    'careerOnly' => (int) $r['CountsTowardSeason'] === 0,
                ];
            }, $rows)]);
        }

        // ---------- GRADE HISTORY ----------
        if ($action === 'grades') {
            pRequirePerson($pdo, $id);
            $rows = pRows($pdo, "
                SELECT rh.ChangeDate, oc.ShortName AS OldGrade, nc.ShortName AS NewGrade, rh.Reason, rh.IsAutomatic,
                       adm.FirstName AS AdmFirst, adm.LastName AS AdmLast
                FROM PlayerRankingHistory rh
                LEFT JOIN Categories oc ON oc.CategoryId = rh.OldCategoryId
                LEFT JOIN Categories nc ON nc.CategoryId = rh.NewCategoryId
                LEFT JOIN Users adm ON adm.UserId = rh.ChangedByAdminId
                WHERE rh.PlayerId = ?
                ORDER BY rh.ChangeDate DESC", [$id]);
            sendJSON(['success' => true, 'grades' => array_map(function ($r) {
                $by = pName($r['AdmFirst'], $r['AdmLast']);
                return [
                    'date' => pIso($r['ChangeDate']),
                    'oldGrade' => pText($r['OldGrade']),
                    'newGrade' => pText($r['NewGrade']),
                    'reason' => pText($r['Reason']),
                    'changedBy' => $by !== '' ? $by : ((int) $r['IsAutomatic'] === 1 ? 'System' : null),
                ];
            }, $rows)]);
        }

        // ---------- ACTIVITY ----------
        if ($action === 'activity') {
            $p = pRequirePerson($pdo, $id);
            $view = ($_GET['view'] ?? 'about') === 'by' ? 'by' : 'about';
            $page = pPage();
            $offset = ($page - 1) * PEOPLE_PAGE_SIZE;
            $limit = $offset + PEOPLE_PAGE_SIZE;

            $who = function ($first, $last, $actorId) {
                if ((int) $actorId === 0) {
                    return 'System';
                }
                $name = pName($first, $last);
                return $name !== '' ? $name : 'Unknown';
            };

            // Done by this person: staff only, Super Admins only
            if ($view === 'by') {
                if (!$isSuper || (int) $p['IsStaffMember'] !== 1) {
                    sendJSON(['success' => false, 'message' => 'Only a Super Admin can see what a staff member has done'], 403);
                }
                $total = (int) pValue($pdo, "SELECT COUNT(*) FROM AuditLog WHERE ActorUserId = ?", [$id]);
                $rows = pRows($pdo, "
                    SELECT l.Action, l.TargetType, l.TargetId, l.Details, l.CreatedDate, l.ActingAsUserId,
                           v.FirstName AS AsFirst, v.LastName AS AsLast
                    FROM AuditLog l
                    LEFT JOIN Users v ON v.UserId = l.ActingAsUserId
                    WHERE l.ActorUserId = ?
                    ORDER BY l.CreatedDate DESC
                    LIMIT " . PEOPLE_PAGE_SIZE . " OFFSET " . (int) $offset, [$id]);
                sendJSON([
                    'success' => true, 'view' => 'by', 'page' => $page, 'pageSize' => PEOPLE_PAGE_SIZE, 'total' => $total,
                    'entries' => array_map(function ($r) {
                        $details = pText($r['Details']);
                        $text = kbfActionName($r['Action']) . ($details ? ': ' . $details : '');
                        if ($r['ActingAsUserId'] !== null) {
                            $text .= ' (viewing as ' . pName($r['AsFirst'], $r['AsLast']) . ')';
                        }
                        return [
                            'date' => pIso($r['CreatedDate']),
                            'description' => $text,
                            'targetType' => $r['TargetType'],
                            'targetId' => $r['TargetId'] !== null ? (int) $r['TargetId'] : null,
                        ];
                    }, $rows),
                ]);
            }

            // Changes to this person: AuditLog, older UserActivityLogs and the
            // ApprovalLogs entry for their registration, merged newest first
            $regIds = pRegistrationIds($pdo, $p['PlayerId']);
            $regIn = $regIds ? implode(',', array_fill(0, count($regIds), '?')) : '';

            $auditWhere = "(l.TargetType = 'user' AND l.TargetId = ?)" . ($regIds ? " OR (l.TargetType = 'registration' AND l.TargetId IN ($regIn))" : '');
            $auditParams = array_merge([$id], $regIds);

            $total = (int) pValue($pdo, "SELECT COUNT(*) FROM AuditLog l WHERE $auditWhere", $auditParams)
                + (int) pValue($pdo, "SELECT COUNT(*) FROM UserActivityLogs WHERE TargetUserId = ?", [$id])
                + ($regIds ? (int) pValue($pdo, "SELECT COUNT(*) FROM ApprovalLogs WHERE LogType = 'Registration' AND ReferenceId IN ($regIn)", $regIds) : 0);

            $entries = [];
            foreach (pRows($pdo, "
                SELECT l.Action, l.Details, l.CreatedDate, l.ActorUserId, a.FirstName, a.LastName
                FROM AuditLog l
                LEFT JOIN Users a ON a.UserId = l.ActorUserId
                WHERE $auditWhere
                ORDER BY l.CreatedDate DESC
                LIMIT " . (int) $limit, $auditParams) as $r) {
                $details = pText($r['Details']);
                $entries[] = [
                    'date' => pIso($r['CreatedDate']),
                    'sort' => (string) $r['CreatedDate'],
                    'description' => kbfActionName($r['Action']) . ($details ? ': ' . $details : ''),
                    'by' => $who($r['FirstName'], $r['LastName'], $r['ActorUserId']),
                ];
            }

            $oldNames = ['Created' => 'Added to the system', 'Updated' => 'Details changed', 'Deactivated' => 'Deactivated'];
            foreach (pRows($pdo, "
                SELECT ActionType, TargetUserDetails, ChangedByAdminName, LogDate, Comments
                FROM UserActivityLogs
                WHERE TargetUserId = ?
                ORDER BY LogDate DESC
                LIMIT " . (int) $limit, [$id]) as $r) {
                $text = $oldNames[$r['ActionType']] ?? pText($r['ActionType']);
                if ($r['ActionType'] === 'Updated' && preg_match('/Updated:\s*(.+)$/', (string) $r['TargetUserDetails'], $m)) {
                    $text .= ': ' . pText($m[1]);
                }
                $entries[] = [
                    'date' => pIso($r['LogDate']),
                    'sort' => (string) $r['LogDate'],
                    'description' => $text,
                    'by' => pText($r['ChangedByAdminName']) ?: 'Unknown',
                ];
            }

            if ($regIds) {
                foreach (pRows($pdo, "
                    SELECT Action, ApprovedByAdminName, LogDate
                    FROM ApprovalLogs
                    WHERE LogType = 'Registration' AND ReferenceId IN ($regIn)
                    ORDER BY LogDate DESC
                    LIMIT " . (int) $limit, $regIds) as $r) {
                    $entries[] = [
                        'date' => pIso($r['LogDate']),
                        'sort' => (string) $r['LogDate'],
                        'description' => 'Registration ' . strtolower((string) $r['Action']),
                        'by' => pText($r['ApprovedByAdminName']) ?: 'Unknown',
                    ];
                }
            }

            usort($entries, function ($a, $b) { return strcmp($b['sort'], $a['sort']); });
            $entries = array_map(function ($e) { unset($e['sort']); return $e; },
                array_slice($entries, $offset, PEOPLE_PAGE_SIZE));

            sendJSON([
                'success' => true, 'view' => 'about', 'page' => $page, 'pageSize' => PEOPLE_PAGE_SIZE,
                'total' => $total, 'entries' => $entries,
            ]);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        error_log('Database error in people.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST
// ============================================
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
}
kbfRequireScalarFields($input);
$action = (string) ($input['action'] ?? '');

// ---------- ADD A PLAYER ----------
if ($action === 'add') {
    if (!hasPermission('players.approve_registrations') || !hasPermission('players.edit_details')) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }

    $errors = [];
    [$firstName, $err] = pCleanName($input['firstName'] ?? '', 'First name');
    if ($err) { $errors['firstName'] = $err; }
    [$lastName, $err] = pCleanName($input['lastName'] ?? '', 'Surname');
    if ($err) { $errors['lastName'] = $err; }
    [$email, $err] = pCleanEmail($input['email'] ?? '');
    if ($err) { $errors['email'] = $err; }
    [$mobile, $err] = pCleanMobile($input['mobile'] ?? '');
    if ($err) { $errors['mobile'] = $err; }
    [$city, $err] = pCleanCity($input['city'] ?? '');
    if ($err) { $errors['city'] = $err; }
    [$postCode, $err] = pCleanPostCode($input['postCode'] ?? '');
    if ($err) { $errors['postCode'] = $err; }
    [$yearOfBirth, $err] = pCleanYear($input['yearOfBirth'] ?? null);
    if ($err) { $errors['yearOfBirth'] = $err; }
    [$photo, $err] = pCleanPhoto($input['photo'] ?? null);
    if ($err) { $errors['photo'] = $err; }

    $gradeId = kbfDefaultGradeId($pdo);
    if (isset($input['gradeId']) && $input['gradeId'] !== '' && $input['gradeId'] !== null) {
        $gradeId = filter_var($input['gradeId'], FILTER_VALIDATE_INT);
        if (!$gradeId || !in_array((int) $gradeId, pActiveGradeIds($pdo), true)) {
            $errors['gradeId'] = 'Choose a grade';
        }
    }
    if (!$gradeId) {
        $errors['gradeId'] = 'Choose a grade';
    }

    if ($errors) {
        sendJSON(['success' => false, 'message' => reset($errors), 'errors' => $errors], 400);
    }

    try {
        // Possible duplicates: the same email, or the same name and city
        $dupes = pRows($pdo, "
            SELECT u.UserId, u.PlayerId, u.FirstName, u.LastName, u.Location, u.IsActive,
                   (CASE WHEN u.Email = ? THEN 1 ELSE 0 END) AS SameEmail
            FROM Users u
            WHERE u.IsRemoved = 0
              AND (u.Email = ? OR (u.FirstName IN (?, ?) AND u.LastName IN (?, ?) AND u.Location IN (?, ?)))
            ORDER BY u.UserId
            LIMIT 10", [$email, $email,
                // as typed, or HTML-escaped as older rows were stored
                $firstName, htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
                $lastName, htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'),
                $city, htmlspecialchars($city, ENT_QUOTES, 'UTF-8')]);
        if ($dupes && empty($input['confirmDuplicates'])) {
            sendJSON([
                'success' => false,
                'needsConfirmation' => true,
                'message' => 'This might be someone who is already on the system. Check the list, then confirm to add them anyway.',
                'duplicates' => array_map(function ($d) {
                    return [
                        'id' => (int) $d['UserId'],
                        'kbfId' => $d['PlayerId'],
                        'name' => pName($d['FirstName'], $d['LastName']),
                        'city' => pText($d['Location']),
                        'active' => (int) $d['IsActive'] === 1,
                        'reason' => (int) $d['SameEmail'] === 1 ? 'Same email address' : 'Same name and city',
                    ];
                }, $dupes),
            ], 409);
        }

        // A repeat of the same one-time request id (double tap, retry) gets
        // the first answer back; no second player is created
        require_once __DIR__ . '/request-ids.php';
        kbfRequestIdBegin($pdo, 'player', is_string($input['requestId'] ?? null) ? $input['requestId'] : null);

        $pdo->beginTransaction();

        // KBF ID: the next number (kept in Settings; kbfNextKbfId takes the
        // table locks in the shared order)
        $kbfId = kbfNextKbfId($pdo);

        // Terms: when staff tick that the terms were explained and agreed to
        // (by a parent or guardian for under-18s), the current version and
        // today's date are recorded; otherwise there is no terms record
        $termsExplained = !empty($input['termsExplained']);
        $termsVersion = $termsExplained ? kbfTermsVersion() : null;

        // Players never have a password, a staff role or a club.
        // Role 'Player' is the display-only value every player row has.
        $stmt = $pdo->prepare("
            INSERT INTO Users
            (PlayerId, Email, PasswordHash, FirstName, LastName, Location, PostCode, PhoneNumber,
             YearOfBirth, ProfilePhoto, Role, CategoryId, TotalPoints, IsActive, CreatedDate,
             TermsVersion, TermsAcceptedDate)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'Player', ?, 0, 1, NOW(), ?, " . ($termsExplained ? 'NOW()' : 'NULL') . ")
        ");
        $stmt->execute([$kbfId, $email, $firstName, $lastName, $city, $postCode, $mobile, $yearOfBirth, $photo, $gradeId, $termsVersion]);
        $newId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO PlayerPerformanceTracker
            (PlayerId, CategoryId, FinalsReached, SemiFinalsReached, GroupStageFailures,
             TotalTournamentsPlayed, Last6MonthsPoints, CareerPoints,
             EligibleForPromotion, FlaggedForDemotion, LastCalculatedDate)
            VALUES (?, ?, 0, 0, 0, 0, 0, 0, 0, 0, NOW())
        ");
        $stmt->execute([$newId, $gradeId]);

        $pdo->commit();

        $gradeShort = (string) pValue($pdo, "SELECT ShortName FROM Categories WHERE CategoryId = ?", [$gradeId]);
        auditLog('player_created', 'user', $newId,
            'Player: ' . pName($firstName, $lastName) . ' (' . $kbfId . '); grade: ' . pText($gradeShort) . '; city: ' . pText($city)
            . ($termsExplained ? '; terms ' . $termsVersion . ' explained by staff' : '; no terms record'));

        // "Send their KBF ID": the player_added email, even when the event is
        // switched off in Notifications, because the admin chose to send it
        $message = pName($firstName, $lastName) . ' was added with KBF ID ' . $kbfId . '.';
        if (!empty($input['sendKbfId'])) {
            require_once __DIR__ . '/notify.php';
            $gradeName = (string) pValue($pdo, "SELECT Name FROM Categories WHERE CategoryId = ?", [$gradeId]);
            $results = notify('player_added', [
                'person' => $newId,
                'forcePerson' => true,
                'values' => [
                    'first_name' => pText($firstName),
                    'full_name' => pName($firstName, $lastName),
                    'kbf_id' => $kbfId,
                    'grade' => $gradeName,
                ],
                'relatedType' => 'user',
                'relatedId' => $newId,
            ]);
            if (in_array($results['person'] ?? '', ['sent', 'waiting', 'failed'], true)) {
                $message .= ' ' . kbfNotifyOutcomeText($results, 'person', 'The email with their KBF ID');
            }
        }

        sendJSON(['success' => true, 'message' => $message, 'id' => $newId, 'kbfId' => $kbfId]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            sendJSON(['success' => false, 'message' => 'Another account already uses that email address.'], 409);
        }
        error_log('Database error in people.php add: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ---------- EDIT DETAILS (never grade, role or club) ----------
if ($action === 'edit') {
    if (!hasPermission('players.edit_details')) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
    foreach (['gradeId', 'categoryId', 'role', 'clubId'] as $blocked) {
        if (array_key_exists($blocked, $input)) {
            sendJSON(['success' => false, 'message' => 'Grades are changed on the Grading page, and roles and clubs are no longer used.'], 400);
        }
    }
    $id = pIdParam($input['id'] ?? null);

    try {
        $p = pRequirePerson($pdo, $id);
        if ((int) $p['IsStaffMember'] === 1 && !$isSuper) {
            sendJSON(['success' => false, 'message' => 'Only a Super Admin can edit a staff member'], 403);
        }
        if ((array_key_exists('email', $input) || array_key_exists('mobile', $input)) && !$canContacts) {
            sendJSON(['success' => false, 'message' => 'You do not have permission to change email addresses or mobile numbers'], 403);
        }

        // field => [label, column, cleaner, contact detail?]
        $fields = [
            'firstName' => ['First name', 'FirstName', function ($v) { return pCleanName($v, 'First name'); }, false],
            'lastName' => ['Surname', 'LastName', function ($v) { return pCleanName($v, 'Surname'); }, false],
            'city' => ['City', 'Location', 'pCleanCity', false],
            'postCode' => ['Post code', 'PostCode', 'pCleanPostCode', true],
            'yearOfBirth' => ['Year of birth', 'YearOfBirth', 'pCleanYear', true],
            'photo' => ['Photo', 'ProfilePhoto', 'pCleanPhoto', false],
            'email' => ['Email', 'Email', 'pCleanEmail', true],
            'mobile' => ['Mobile', 'PhoneNumber', 'pCleanMobile', true],
        ];

        $sets = [];
        $params = [];
        $changes = [];
        $errors = [];
        foreach ($fields as $key => [$label, $column, $clean, $private]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            [$value, $err] = call_user_func($clean, $input[$key]);
            if ($err) {
                $errors[$key] = $err;
                continue;
            }
            $old = $p[$column];
            if ((string) $old === (string) $value) {
                continue;
            }
            $sets[] = "$column = ?";
            $params[] = $value;
            if (in_array($key, ['firstName', 'lastName', 'city', 'postCode'], true)
                    && $value !== null && pText($old) === (string) $value) {
                // Same text, only stored HTML-encoded before: saved as typed,
                // not a change
                continue;
            }
            if ($private) {
                // Contact details are never written to the log
                $changes[] = $label . ' changed';
            } elseif ($key === 'photo') {
                $changes[] = $value === null ? 'Photo removed' : ($old ? 'Photo changed' : 'Photo added');
            } else {
                $changes[] = $label . ': "' . pText($old) . '" to "' . pText($value) . '"';
            }
        }
        if ($errors) {
            sendJSON(['success' => false, 'message' => reset($errors), 'errors' => $errors], 400);
        }
        if (!$sets) {
            sendJSON(['success' => true, 'message' => 'Nothing was changed.', 'changed' => false]);
        }

        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE Users SET " . implode(', ', $sets) . " WHERE UserId = ? AND IsRemoved = 0");
        $stmt->execute($params);

        if (!$changes) {
            sendJSON(['success' => true, 'message' => 'Nothing was changed.', 'changed' => false]);
        }

        auditLog('player_details_edited', 'user', $id, pName($p['FirstName'], $p['LastName']) . ': ' . implode('; ', $changes));

        sendJSON(['success' => true, 'message' => 'Details saved.', 'changed' => true]);

    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            sendJSON(['success' => false, 'message' => 'Another account already uses that email address.'], 409);
        }
        error_log('Database error in people.php edit: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ---------- DEACTIVATE OR REACTIVATE ----------
if ($action === 'setActive') {
    $id = pIdParam($input['id'] ?? null);
    if (!array_key_exists('active', $input) || !is_bool($input['active'])) {
        sendJSON(['success' => false, 'message' => 'Say whether the person should be active'], 400);
    }
    $active = $input['active'];
    if ($id === $myId) {
        sendJSON(['success' => false, 'message' => 'You cannot deactivate your own account'], 403);
    }

    try {
        $p = pRequirePerson($pdo, $id);
        $isStaffMember = (int) $p['IsStaffMember'] === 1;
        if ($isStaffMember) {
            if (!$isSuper) {
                sendJSON(['success' => false, 'message' => 'Only a Super Admin can deactivate or reactivate a staff member'], 403);
            }
        } elseif (!hasPermission('players.edit_details') || !hasPermission('players.change_grades')) {
            sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
        }

        $was = (int) $p['IsActive'] === 1;
        if ($was === $active) {
            sendJSON(['success' => true, 'message' => $active ? 'Already active.' : 'Already deactivated.', 'changed' => false]);
        }

        $stmt = $pdo->prepare("UPDATE Users SET IsActive = ? WHERE UserId = ? AND IsRemoved = 0");
        $stmt->execute([$active ? 1 : 0, $id]);

        $who = $isStaffMember ? 'staff' : 'player';
        auditLog($who . ($active ? '_reactivated' : '_deactivated'), 'user', $id,
            ($isStaffMember ? 'Staff member' : 'Player') . ': ' . pName($p['FirstName'], $p['LastName'])
            . '; active: ' . ($was ? 'yes' : 'no') . ' to ' . ($active ? 'yes' : 'no'));

        sendJSON(['success' => true, 'message' => $active ? 'Reactivated.' : 'Deactivated.', 'changed' => true]);

    } catch (PDOException $e) {
        error_log('Database error in people.php setActive: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ---------- REMOVE AT THEIR REQUEST ----------
if ($action === 'remove') {
    if (!$isSuper) {
        sendJSON(['success' => false, 'message' => 'Only a Super Admin can remove a player'], 403);
    }
    if (($input['confirm'] ?? false) !== true) {
        sendJSON(['success' => false, 'message' => 'Confirm that you want to remove this player. This cannot be undone.'], 400);
    }
    $id = pIdParam($input['id'] ?? null);
    if ($id === $myId) {
        sendJSON(['success' => false, 'message' => 'You cannot remove your own account'], 403);
    }

    try {
        $p = pRequirePerson($pdo, $id);
        if ((int) $p['IsStaffMember'] === 1) {
            sendJSON(['success' => false, 'message' => 'This person still has staff access. Remove their access first, then remove them.'], 409);
        }

        $kbfId = $p['PlayerId'];
        $oldEmail = (string) $p['Email'];
        $placeholderEmail = 'removed-' . $id . '@invalid.local';
        $photos = [$p['ProfilePhoto']];

        $pdo->beginTransaction();

        // Their registration rows (by KBF ID, or by email for anyone without one)
        if ($kbfId !== null && $kbfId !== '') {
            $regs = pRows($pdo, "SELECT RegistrationId, ProfilePhoto, FirstName, LastName, Email, PhoneNumber, Location FROM PlayerRegistrations WHERE PlayerId = ? FOR UPDATE", [$kbfId]);
        } elseif ($oldEmail !== '') {
            $regs = pRows($pdo, "SELECT RegistrationId, ProfilePhoto, FirstName, LastName, Email, PhoneNumber, Location FROM PlayerRegistrations WHERE Email = ? FOR UPDATE", [$oldEmail]);
        } else {
            $regs = [];
        }
        $regIds = array_map('intval', array_column($regs, 'RegistrationId'));
        foreach ($regs as $r) {
            $photos[] = $r['ProfilePhoto'];
        }

        // 1. Users: personal details cleared; UserId, KBF ID, grade and points kept
        $stmt = $pdo->prepare("
            UPDATE Users
            SET FirstName = 'Former', LastName = 'member',
                Email = ?, PhoneNumber = NULL, PostCode = NULL, YearOfBirth = NULL, Location = '',
                PasswordHash = NULL, UnsubscribeToken = NULL,
                TwoFactorSecret = NULL, TwoFactorEnabledDate = NULL, TwoFactorLastStep = NULL, TwoFactorRequired = 0,
                ProfilePhoto = NULL,
                EmailOptOut = 1, IsActive = 0, LoginEnabled = 0,
                IsRemoved = 1, RemovedDate = NOW(), RemovedByUserId = ?
            WHERE UserId = ?
        ");
        $stmt->execute([$placeholderEmail, $myId, $id]);
        $pdo->prepare("DELETE FROM TwoFactorBackupCodes WHERE UserId = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM UserPermissions WHERE UserId = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM StaffRoles WHERE UserId = ?")->execute([$id]);

        // 2. Their registration rows: the same personal fields cleared
        if ($regIds) {
            $in = implode(',', array_fill(0, count($regIds), '?'));
            $stmt = $pdo->prepare("
                UPDATE PlayerRegistrations
                SET FirstName = 'Former', LastName = 'member', Email = ?, PhoneNumber = '',
                    PostCode = NULL, YearOfBirth = NULL, Location = '', ProfilePhoto = NULL,
                    SubmittedIpAddress = ''
                WHERE RegistrationId IN ($in)
            ");
            $stmt->execute(array_merge([$placeholderEmail], $regIds));
        }

        // 2b. Their name, email, phone and town in stored text (activity log,
        // email log, approval history, old activity log, notifications):
        // replaced with "Former member" / "removed"; emails to their address
        // lose their text; their notifications and reset links are deleted.
        // Before step 3, which replaces their address in the email log.
        // Matching rule (only this person's own values; other names in the
        // same row stay): see kbfErasePersonText() in api/data-retention.php.
        require_once __DIR__ . '/data-retention.php';
        $identities = [[
            'firstName' => $p['FirstName'], 'lastName' => $p['LastName'], 'email' => $oldEmail,
            'phone' => $p['PhoneNumber'], 'location' => $p['Location'],
        ]];
        foreach ($regs as $r) {
            $identities[] = [
                'firstName' => $r['FirstName'], 'lastName' => $r['LastName'], 'email' => $r['Email'],
                'phone' => $r['PhoneNumber'], 'location' => $r['Location'],
            ];
        }
        kbfErasePersonText($pdo, $id, $regIds, $identities, [$oldEmail], 'Former member');

        // 3. Emails to their address: address and name replaced
        if ($oldEmail !== '') {
            $pdo->prepare("
                UPDATE EmailLog SET Status = 'failed', LastError = 'Recipient removed at their request'
                WHERE ToEmail = ? AND Status = 'waiting'")->execute([$oldEmail]);
            $pdo->prepare("UPDATE EmailLog SET ToEmail = 'removed', ToName = 'removed' WHERE ToEmail = ?")->execute([$oldEmail]);
            $pdo->prepare("UPDATE EmailQueue SET Email = 'removed', RecipientName = 'removed' WHERE Email = ? OR UserId = ?")
                ->execute([$oldEmail, $id]);
        } else {
            $pdo->prepare("UPDATE EmailQueue SET Email = 'removed', RecipientName = 'removed' WHERE UserId = ?")->execute([$id]);
        }

        // 4. Older logs that name them
        if ($regIds) {
            $in = implode(',', array_fill(0, count($regIds), '?'));
            $pdo->prepare("
                UPDATE ApprovalLogs SET ReferenceName = 'Former member', ReferenceDetails = 'Removed at their request'
                WHERE LogType = 'Registration' AND ReferenceId IN ($in)")->execute($regIds);
        }
        $pdo->prepare("UPDATE ApprovalLogs SET ApprovedByAdminName = 'Former member' WHERE ApprovedByAdminId = ?")->execute([$id]);
        $pdo->prepare("
            UPDATE UserActivityLogs SET TargetUserName = 'Former member', TargetUserDetails = 'Removed at their request'
            WHERE TargetUserId = ?")->execute([$id]);
        $pdo->prepare("UPDATE UserActivityLogs SET ChangedByAdminName = 'Former member' WHERE ChangedByAdminId = ?")->execute([$id]);

        // 5. Promotion news about them: hidden
        $hiddenPosts = 0;
        if ($kbfId !== null && $kbfId !== '') {
            $stmt = $pdo->prepare("
                UPDATE Announcements SET IsVisible = 0
                WHERE Title LIKE '%Player Promotion:%'
                  AND Content REGEXP ?
                  AND IsVisible = 1
            ");
            // The exact KBF ID, so KBFUK01 never matches KBFUK012
            $stmt->execute([preg_quote($kbfId, '/') . '([^0-9]|$)']);
            $hiddenPosts = $stmt->rowCount();
        }

        $pdo->commit();

        // Photo files go only after the database change is saved
        foreach (array_unique(array_filter($photos)) as $path) {
            pDeletePhotoFile($path);
        }

        auditLog('player_removed_at_request', 'user', $id, 'KBF ID: ' . ($kbfId ?: 'none'));

        sendJSON([
            'success' => true,
            'message' => 'The player was removed at their request. Their personal details are gone and this cannot be undone. '
                . 'Their KBF ID, grade and points history are kept and shown as "Former member".'
                . ($hiddenPosts > 0 ? ' ' . $hiddenPosts . ' promotion news post' . ($hiddenPosts === 1 ? ' was' : 's were') . ' hidden.' : '')
                . ' Their name was also replaced in the logs and emails. Please check other news posts, broadcasts and gallery photos by hand.',
            'kbfId' => $kbfId,
        ]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Database error in people.php remove: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'The player could not be removed. Nothing was changed.'], 500);
    }
}

sendJSON(['success' => false, 'message' => 'Unknown action'], 400);
