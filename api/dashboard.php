<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';

// ============================================
// STAFF DASHBOARD
//
//   GET   the to-do list, figures and panels for the signed-in staff member
//
// Open to any staff member (a Super Admin or anyone with a permission).
// Reading is allowed while impersonating. Each part is worked out only when
// the person has the permission shown, and left out otherwise:
//   me, players, upcoming      any staff
//   registrations              players.approve_registrations
//   approvals                  tournaments.approve
//   eligible                   players.change_grades
//   team                       players.approve_registrations AND tournaments.approve
//   mine                       players.approve_registrations WITHOUT tournaments.approve
//   myTournaments              tournaments.organise_own
// Never returns contact details (email, phone, post code, year of birth).
// Names are decoded to plain text; the page escapes every value.
// ============================================

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!isStaff()) {
    sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
}

$userId = (int) $_SESSION['user_id'];
$isSuper = isSuperAdmin();
$can = [
    'registrations' => hasPermission('players.approve_registrations'),
    'approve' => hasPermission('tournaments.approve'),
    'grades' => hasPermission('players.change_grades'),
    'organise' => hasPermission('tournaments.organise_own'),
];

function dashValue($pdo, $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function dashRows($pdo, $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Stored names were HTML-escaped on the way in; send plain text
function dashText($value) {
    return trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
}

function dashDate($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? null : date('Y-m-d', $ts);
}

// Only site paths for photos (the page checks again)
function dashPhoto($value) {
    $path = (string) $value;
    return preg_match('#^/uploads/[A-Za-z0-9_/\-]+\.(jpg|jpeg|png|webp)$#i', $path) ? $path : null;
}

function dashRegistrationRow(array $r) {
    return [
        'id' => (int) $r['RegistrationId'],
        'name' => dashText($r['FirstName'] . ' ' . $r['LastName']),
        'city' => dashText($r['Location']),
        'grade' => dashText($r['ShortName']),
        'photo' => dashPhoto($r['ProfilePhoto']),
        'days' => max(0, (int) $r['DaysWaited']),
    ];
}

try {
    $today = date('Y-m-d');
    $response = ['success' => true];

    // ============================================
    // ME (any staff)
    // ============================================
    $me = dashRows($pdo, "SELECT FirstName FROM Users WHERE UserId = ?", [$userId]);
    $labels = [];
    if (!$isSuper) {
        try {
            $rows = dashRows($pdo, "
                SELECT p.Label
                FROM UserPermissions up
                JOIN Permissions p ON p.PermissionKey = up.PermissionKey
                WHERE up.UserId = ?
                ORDER BY p.SortOrder, p.PermissionKey", [$userId]);
            foreach ($rows as $row) {
                $labels[] = dashText($row['Label']);
            }
        } catch (PDOException $e) {
            error_log('dashboard.php: permission labels: ' . $e->getMessage());
        }
    }
    $response['me'] = [
        'firstName' => $me ? dashText($me[0]['FirstName']) : '',
        'isSuperAdmin' => $isSuper,
        'permissions' => $labels,
    ];

    // ============================================
    // PLAYERS (any staff)
    // ============================================
    $response['players'] = [
        'active' => (int) dashValue($pdo, "SELECT COUNT(*) FROM Users WHERE IsActive = 1 AND CategoryId IS NOT NULL"),
        'thisMonth' => (int) dashValue($pdo, "
            SELECT COUNT(*) FROM PlayerRegistrations
            WHERE Status = 'Approved' AND ReviewedDate >= ?", [date('Y-m-01')]),
    ];

    // ============================================
    // UPCOMING (any staff): approved tournaments today or later
    // ============================================
    $next = dashRows($pdo, "
        SELECT TournamentId, Name, TournamentDate, Location
        FROM Tournaments
        WHERE Status = 'Approved' AND DATE(TournamentDate) >= ?
        ORDER BY TournamentDate ASC, TournamentId ASC
        LIMIT 4", [$today]);
    $response['upcoming'] = [
        'count' => (int) dashValue($pdo, "
            SELECT COUNT(*) FROM Tournaments
            WHERE Status = 'Approved' AND DATE(TournamentDate) >= ?", [$today]),
        'next' => array_map(function ($t) {
            return [
                'id' => (int) $t['TournamentId'],
                'name' => dashText($t['Name']),
                'date' => dashDate($t['TournamentDate']),
                'town' => dashText($t['Location']),
            ];
        }, $next),
    ];

    // ============================================
    // REGISTRATIONS (players.approve_registrations)
    // ============================================
    if ($can['registrations']) {
        $summary = dashRows($pdo, "
            SELECT COUNT(*) AS waiting,
                   SUM(CASE WHEN SubmittedDate > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS underWeek,
                   SUM(CASE WHEN SubmittedDate <= DATE_SUB(NOW(), INTERVAL 7 DAY)
                             AND SubmittedDate >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS weekToMonth,
                   SUM(CASE WHEN SubmittedDate < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS overMonth,
                   MIN(SubmittedDate) AS oldest
            FROM PlayerRegistrations
            WHERE Status = 'Pending'")[0];

        $listSql = "
            SELECT pr.RegistrationId, pr.FirstName, pr.LastName, pr.Location, pr.ProfilePhoto,
                   c.ShortName, DATEDIFF(NOW(), pr.SubmittedDate) AS DaysWaited
            FROM PlayerRegistrations pr
            LEFT JOIN Categories c ON c.CategoryId = pr.CategoryId
            WHERE pr.Status = 'Pending'
            ORDER BY pr.SubmittedDate %s, pr.RegistrationId %s
            LIMIT 5";

        $response['registrations'] = [
            'waiting' => (int) $summary['waiting'],
            'underWeek' => (int) $summary['underWeek'],
            'weekToMonth' => (int) $summary['weekToMonth'],
            'overMonth' => (int) $summary['overMonth'],
            'oldestDate' => dashDate($summary['oldest']),
            'oldest' => array_map('dashRegistrationRow', dashRows($pdo, sprintf($listSql, 'ASC', 'ASC'))),
            'newest' => array_map('dashRegistrationRow', dashRows($pdo, sprintf($listSql, 'DESC', 'DESC'))),
        ];
    }

    // ============================================
    // APPROVALS (tournaments.approve)
    // ============================================
    if ($can['approve']) {
        $response['approvals'] = [
            'tournaments' => (int) dashValue($pdo, "SELECT COUNT(*) FROM Tournaments WHERE Status = 'Pending'"),
            'results' => (int) dashValue($pdo, "SELECT COUNT(*) FROM ResultsApprovalQueue WHERE Status = 'Pending'"),
        ];
    }

    // ============================================
    // ELIGIBLE FOR PROMOTION (players.change_grades)
    // The season ends the day before the next season starts.
    // ============================================
    if ($can['grades']) {
        $season = kbfSeasonAt(kbfSeasonSettings($pdo)['months']);
        $response['eligible'] = [
            'count' => (int) dashValue($pdo, "
                SELECT COUNT(*) FROM PlayerPerformanceTracker pt
                JOIN Users u ON u.UserId = pt.PlayerId
                WHERE pt.EligibleForPromotion = 1 AND u.IsActive = 1"),
            'seasonEnds' => date('Y-m-d', strtotime($season['nextResetDate'] . ' -1 day')),
        ];
    }

    // ============================================
    // TEAM, LAST 30 DAYS (registrations AND tournaments.approve)
    // ============================================
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    if ($can['registrations'] && $can['approve']) {
        $counts = dashRows($pdo, "
            SELECT LogType, Action, COUNT(*) AS n
            FROM ApprovalLogs
            WHERE LogDate >= ?
            GROUP BY LogType, Action", [$since]);
        $figure = function ($type, $action) use ($counts) {
            foreach ($counts as $c) {
                if ($c['LogType'] === $type && $c['Action'] === $action) {
                    return (int) $c['n'];
                }
            }
            return 0;
        };

        $people = dashRows($pdo, "
            SELECT al.ApprovedByAdminId,
                   MAX(u.FirstName) AS FirstName, MAX(u.LastName) AS LastName,
                   MAX(al.ApprovedByAdminName) AS LoggedName,
                   COUNT(*) AS n
            FROM ApprovalLogs al
            LEFT JOIN Users u ON u.UserId = al.ApprovedByAdminId
            WHERE al.LogDate >= ?
            GROUP BY al.ApprovedByAdminId
            ORDER BY n DESC, LoggedName ASC
            LIMIT 5", [$since]);

        $response['team'] = [
            'playersApproved' => $figure('Registration', 'Approved'),
            'playersRejected' => $figure('Registration', 'Rejected'),
            'tournamentsApproved' => $figure('Tournament', 'Approved'),
            'resultsApproved' => $figure('Results', 'Approved'),
            'top' => array_map(function ($p) {
                $name = trim((string) $p['FirstName'] . ' ' . (string) $p['LastName']);
                return [
                    'name' => dashText($name !== '' ? $name : $p['LoggedName']),
                    'count' => (int) $p['n'],
                ];
            }, $people),
        ];
    }

    // ============================================
    // MINE, LAST 30 DAYS (registrations WITHOUT tournaments.approve)
    // ============================================
    if ($can['registrations'] && !$can['approve']) {
        $response['mine'] = [
            'approved' => (int) dashValue($pdo, "
                SELECT COUNT(*) FROM ApprovalLogs
                WHERE LogType = 'Registration' AND Action = 'Approved'
                  AND ApprovedByAdminId = ? AND LogDate >= ?", [$userId, $since]),
            'teamDecisions' => (int) dashValue($pdo, "
                SELECT COUNT(*) FROM ApprovalLogs
                WHERE LogType = 'Registration' AND LogDate >= ?", [$since]),
        ];
    }

    // ============================================
    // MY TOURNAMENTS (tournaments.organise_own)
    // Upcoming or dated in the last 60 days, soonest first, at most 6.
    // ============================================
    if ($can['organise']) {
        $latestResults = "(SELECT ra.Status FROM ResultsApprovalQueue ra
                           WHERE ra.TournamentId = t.TournamentId
                           ORDER BY ra.SubmittedDate DESC, ra.ApprovalId DESC LIMIT 1)";

        $rows = dashRows($pdo, "
            SELECT t.TournamentId, t.Name, t.TournamentDate, t.Venue, t.Location, t.Status,
                   $latestResults AS ResultsStatus
            FROM Tournaments t
            WHERE t.CreatedByAdminId = ?
              AND t.Status IN ('Pending', 'Approved', 'Completed')
              AND DATE(t.TournamentDate) >= ?
            ORDER BY t.TournamentDate ASC, t.TournamentId ASC
            LIMIT 6", [$userId, date('Y-m-d', strtotime('-60 days'))]);

        $items = [];
        foreach ($rows as $t) {
            $date = dashDate($t['TournamentDate']);
            $results = (string) $t['ResultsStatus'];
            if ($t['Status'] === 'Pending') {
                $status = 'Waiting approval';
            } elseif ($t['Status'] === 'Completed' || $results === 'Approved') {
                $status = 'Completed';
            } elseif ($results === 'Pending') {
                $status = 'Results waiting approval';
            } elseif ($date !== null && $date >= $today) {
                $status = 'Approved';
            } else {
                $status = 'Results due';
            }
            $items[] = [
                'id' => (int) $t['TournamentId'],
                'name' => dashText($t['Name']),
                'date' => $date,
                'venue' => dashText($t['Venue']),
                'town' => dashText($t['Location']),
                'status' => $status,
            ];
        }

        $nextOwn = dashValue($pdo, "
            SELECT MIN(TournamentDate) FROM Tournaments
            WHERE CreatedByAdminId = ? AND Status = 'Approved' AND DATE(TournamentDate) >= ?", [$userId, $today]);

        $response['myTournaments'] = [
            'items' => $items,
            'comingUp' => (int) dashValue($pdo, "
                SELECT COUNT(*) FROM Tournaments
                WHERE CreatedByAdminId = ? AND Status = 'Approved' AND DATE(TournamentDate) >= ?", [$userId, $today]),
            'nextDate' => dashDate($nextOwn),
            'waitingApproval' => (int) dashValue($pdo, "
                SELECT COUNT(*) FROM Tournaments
                WHERE CreatedByAdminId = ? AND Status = 'Pending'", [$userId]),
            'resultsDue' => (int) dashValue($pdo, "
                SELECT COUNT(*) FROM Tournaments t
                WHERE t.CreatedByAdminId = ? AND t.Status = 'Approved' AND DATE(t.TournamentDate) < ?
                  AND NOT EXISTS (
                      SELECT 1 FROM ResultsApprovalQueue ra
                      WHERE ra.TournamentId = t.TournamentId AND ra.Status IN ('Pending', 'Approved')
                  )", [$userId, $today]),
            'posts' => (int) dashValue($pdo, "
                SELECT COUNT(*) FROM Announcements WHERE CreatedByAdminId = ?", [$userId]),
        ];
    }

    sendJSON($response);

} catch (PDOException $e) {
    error_log('Database error in dashboard.php: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'Could not load the dashboard. Please try again.'], 500);
}
