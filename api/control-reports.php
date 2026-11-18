<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/report-helpers.php';

// ============================================
// CONTROL CENTRE: REPORTS AND EXPORTS (control.reports)
//
//   GET ?report=players|registrations|tournaments|points|grades|staff
//       &offset=0            a page of 50 rows, the summary and (offset 0)
//                            the filter choices
//       &download=1          the whole filtered report as CSV (streamed)
//
//   players        grade, location, status (active | inactive | all)
//   registrations  from, to (YYYY-MM-DD)
//   tournaments    season, organiser, status
//   points         season, grade, tournament
//   grades         season, grade, type (promotion | other | moved)
//   staff          Super Admin only, no filters
//
// Email and phone columns only with players.contact_details. Reading is
// allowed while impersonating; downloads are not. Every download is written
// to AuditLog as report_downloaded with the report, filters and row count.
// ============================================

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
requirePermission('control.reports');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

$reportKey = is_string($_GET['report'] ?? null) ? $_GET['report'] : '';
$download = !empty($_GET['download']);
$canContacts = hasPermission('players.contact_details');

if ($reportKey === 'staff' && !isSuperAdmin()) {
    sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
}
if ($download) {
    requireNotImpersonating();
}

// ---------- helpers ----------
function plain($value) {
    return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
}

function fullName($first, $last) {
    return trim(plain($first) . ' ' . plain($last));
}

function gradeIdParam() {
    $id = filter_var($_GET['grade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id ?: null;
}

function seasonParam() {
    $key = $_GET['season'] ?? '';
    return ($key === '' || $key === 'all') ? null : kbfSeasonRange($key);
}

function gradeOptions($pdo, $includeRetired = false) {
    $sql = "SELECT CategoryId, ShortName, Name FROM Categories"
        . ($includeRetired ? '' : ' WHERE ' . kbfActiveGradeCondition($pdo))
        . " ORDER BY DisplayOrder, CategoryId";
    return array_map(function ($g) {
        return ['value' => (string) $g['CategoryId'], 'label' => plain($g['ShortName']) . ' - ' . plain($g['Name'])];
    }, $pdo->query($sql)->fetchAll());
}

function seasonOptions($pdo, $table, $column) {
    return array_merge([['value' => 'all', 'label' => 'All time']], array_map(function ($s) {
        return ['value' => $s['key'], 'label' => $s['name']];
    }, kbfSeasonsWithData($pdo, $table, $column)));
}

// ============================================
// REPORT DEFINITIONS
// Each returns: title, from, select, where[], params[], order, columns
// [[key, label]], map(row) => [key => value], summary(), options()
// ============================================
function reportPlayers($pdo, $canContacts) {
    // Players removed at their request are never in exports
    $where = ['u.CategoryId IS NOT NULL', 'u.IsRemoved = 0'];
    $params = [];
    $filters = [];

    $status = $_GET['status'] ?? 'active';
    if (!in_array($status, ['active', 'inactive', 'all'], true)) {
        $status = 'active';
    }
    if ($status !== 'all') {
        $where[] = 'u.IsActive = ' . ($status === 'active' ? '1' : '0');
    }
    $filters['status'] = $status;

    $location = is_string($_GET['location'] ?? null) ? trim($_GET['location']) : '';
    if ($location !== '') {
        $where[] = 'u.Location = ?';
        $params[] = htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); // stored escaped
        $filters['location'] = $location;
    }

    // The summary ignores the grade filter, so every bar stays visible
    $summaryWhere = $where;
    $summaryParams = $params;

    $grade = gradeIdParam();
    if ($grade) {
        $where[] = 'u.CategoryId = ?';
        $params[] = $grade;
        $filters['grade'] = $grade;
    }

    $columns = [
        ['kbfId', 'KBF ID'], ['name', 'Name'], ['location', 'Location'], ['grade', 'Grade'],
        ['seasonPoints', 'Season points'], ['careerPoints', 'Career points'], ['finals', 'Finals reached'],
        ['active', 'Active'], ['joined', 'Joined'],
    ];
    if ($canContacts) {
        $columns[] = ['email', 'Email'];
        $columns[] = ['phone', 'Phone'];
    }

    return [
        'title' => 'Players',
        'from' => 'Users u
            LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
            LEFT JOIN PlayerPerformanceTracker pt ON pt.PlayerId = u.UserId',
        'select' => 'u.PlayerId, u.FirstName, u.LastName, u.Location, c.ShortName, u.TotalPoints,
            pt.CareerPoints, pt.FinalsReached, u.IsActive, u.CreatedDate'
            . ($canContacts ? ', u.Email, u.PhoneNumber' : ''),
        'where' => $where,
        'params' => $params,
        'order' => 'c.DisplayOrder ASC, u.TotalPoints DESC, u.LastName ASC, u.FirstName ASC',
        'columns' => $columns,
        'filters' => $filters,
        'map' => function ($r) use ($canContacts) {
            $row = [
                'kbfId' => (string) $r['PlayerId'],
                'name' => fullName($r['FirstName'], $r['LastName']),
                'location' => plain($r['Location']),
                'grade' => plain($r['ShortName']),
                'seasonPoints' => (int) $r['TotalPoints'],
                'careerPoints' => (int) $r['CareerPoints'],
                'finals' => (int) $r['FinalsReached'],
                'active' => (int) $r['IsActive'] === 1 ? 'Active' : 'Inactive',
                'joined' => kbfCsvDate($r['CreatedDate']),
            ];
            if ($canContacts) {
                $row['email'] = (string) $r['Email'];
                $row['phone'] = plain($r['PhoneNumber']);
            }
            return $row;
        },
        'summary' => function () use ($pdo, $summaryWhere, $summaryParams) {
            $stmt = $pdo->prepare("
                SELECT u.CategoryId, COUNT(*) AS n FROM Users u
                WHERE " . implode(' AND ', $summaryWhere) . "
                GROUP BY u.CategoryId
            ");
            $stmt->execute($summaryParams);
            $counts = [];
            foreach ($stmt->fetchAll() as $r) {
                $counts[(int) $r['CategoryId']] = (int) $r['n'];
            }
            $bars = [];
            $total = 0;
            foreach ($pdo->query("SELECT CategoryId, ShortName, Name, " . (kbfCategoriesHaveActiveFlag($pdo) ? 'IsActive' : '1') . " AS IsActive FROM Categories ORDER BY DisplayOrder, CategoryId")->fetchAll() as $g) {
                $n = $counts[(int) $g['CategoryId']] ?? 0;
                if ((int) $g['IsActive'] !== 1 && $n === 0) {
                    continue;
                }
                $bars[] = ['value' => (string) $g['CategoryId'], 'label' => plain($g['ShortName']), 'name' => plain($g['Name']), 'count' => $n];
                $total += $n;
            }
            return ['type' => 'gradeBars', 'line' => $total . ' player' . ($total === 1 ? '' : 's'), 'bars' => $bars];
        },
        'options' => function () use ($pdo) {
            $locations = $pdo->query("
                SELECT DISTINCT Location FROM Users
                WHERE CategoryId IS NOT NULL AND IsRemoved = 0 AND Location IS NOT NULL AND Location <> ''
                ORDER BY Location
            ")->fetchAll(PDO::FETCH_COLUMN);
            return [
                ['name' => 'grade', 'label' => 'Grade', 'choices' => array_merge([['value' => '', 'label' => 'All grades']], gradeOptions($pdo))],
                ['name' => 'location', 'label' => 'Location', 'choices' => array_merge([['value' => '', 'label' => 'All locations']],
                    array_map(function ($l) { return ['value' => plain($l), 'label' => plain($l)]; }, $locations))],
                ['name' => 'status', 'label' => 'Status', 'choices' => [
                    ['value' => 'active', 'label' => 'Active'], ['value' => 'inactive', 'label' => 'Inactive'], ['value' => 'all', 'label' => 'Active and inactive']]],
            ];
        },
    ];
}

function reportRegistrations($pdo) {
    // Leave out the registration of anyone removed at their request
    $where = ['NOT EXISTS (SELECT 1 FROM Users ru WHERE ru.PlayerId = pr.PlayerId AND ru.IsRemoved = 1)'];
    $params = [];
    $filters = [];
    $from = kbfDateParam($_GET['from'] ?? null);
    $to = kbfDateParam($_GET['to'] ?? null);
    if ($from) {
        $where[] = 'pr.SubmittedDate >= ?';
        $params[] = $from . ' 00:00:00';
        $filters['from'] = $from;
    }
    if ($to) {
        $where[] = 'pr.SubmittedDate < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $to;
        $filters['to'] = $to;
    }

    return [
        'title' => 'Registrations',
        'from' => 'PlayerRegistrations pr
            LEFT JOIN Categories c ON c.CategoryId = pr.CategoryId
            LEFT JOIN Users rv ON rv.UserId = pr.ReviewedByAdminId',
        'select' => 'pr.SubmittedDate, pr.FirstName, pr.LastName, pr.Location, c.ShortName, pr.Status,
            rv.FirstName AS RvFirst, rv.LastName AS RvLast, pr.ReviewedDate',
        'where' => $where,
        'params' => $params,
        'order' => 'pr.SubmittedDate DESC',
        'columns' => [['submitted', 'Submitted'], ['name', 'Name'], ['location', 'Location'], ['grade', 'Chosen grade'],
            ['result', 'Result'], ['decidedBy', 'Decided by'], ['decided', 'Decision date']],
        'filters' => $filters,
        'map' => function ($r) {
            $results = ['Approved' => 'Approved', 'Rejected' => 'Rejected', 'Pending' => 'Waiting'];
            return [
                'submitted' => kbfCsvDate($r['SubmittedDate']),
                'name' => fullName($r['FirstName'], $r['LastName']),
                'location' => plain($r['Location']),
                'grade' => plain($r['ShortName']),
                'result' => $results[$r['Status']] ?? (string) $r['Status'],
                'decidedBy' => $r['Status'] === 'Pending' ? '' : fullName($r['RvFirst'], $r['RvLast']),
                'decided' => $r['Status'] === 'Pending' ? '' : kbfCsvDate($r['ReviewedDate']),
            ];
        },
        'summary' => function () use ($pdo, $where, $params) {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(pr.SubmittedDate, '%Y-%m') AS ym, COUNT(*) AS received,
                       SUM(pr.Status = 'Approved') AS approved, SUM(pr.Status = 'Rejected') AS rejected,
                       SUM(pr.Status = 'Pending') AS waiting
                FROM PlayerRegistrations pr
                WHERE " . implode(' AND ', $where) . "
                GROUP BY ym ORDER BY ym
            ");
            $stmt->execute($params);
            $months = [];
            $totals = ['received' => 0, 'approved' => 0, 'rejected' => 0, 'waiting' => 0];
            foreach ($stmt->fetchAll() as $r) {
                $ts = strtotime($r['ym'] . '-01');
                $m = [
                    'month' => $ts ? date('M Y', $ts) : (string) $r['ym'],
                    'received' => (int) $r['received'], 'approved' => (int) $r['approved'],
                    'rejected' => (int) $r['rejected'], 'waiting' => (int) $r['waiting'],
                ];
                foreach ($totals as $k => $v) {
                    $totals[$k] += $m[$k];
                }
                $months[] = $m;
            }
            $stmt = $pdo->prepare("
                SELECT AVG(DATEDIFF(pr.ReviewedDate, pr.SubmittedDate)) FROM PlayerRegistrations pr
                WHERE " . implode(' AND ', $where) . "
                  AND pr.Status IN ('Approved', 'Rejected') AND pr.ReviewedDate IS NOT NULL
            ");
            $stmt->execute($params);
            $avg = $stmt->fetchColumn();
            $avgText = $avg === null || $avg === false ? null : round((float) $avg, 1);
            return [
                'type' => 'monthBars',
                'line' => $totals['received'] . ' received, ' . $totals['approved'] . ' approved, ' . $totals['rejected'] . ' rejected, '
                    . $totals['waiting'] . ' waiting' . ($avgText !== null ? '. Average ' . $avgText . ' days to a decision.' : '.'),
                'months' => $months,
                'averageDays' => $avgText,
            ];
        },
        'options' => function () {
            return [
                ['name' => 'from', 'label' => 'From', 'type' => 'date'],
                ['name' => 'to', 'label' => 'To', 'type' => 'date'],
            ];
        },
    ];
}

function reportTournaments($pdo) {
    $organiserSql = organiserNameSql('t', 'c');
    $where = ['1 = 1'];
    $params = [];
    $filters = [];
    $season = seasonParam();
    if ($season) {
        $where[] = 't.TournamentDate >= ? AND t.TournamentDate < ?';
        $params[] = $season['from'];
        $params[] = $season['to'];
        $filters['season'] = $_GET['season'];
    }
    $organiser = is_string($_GET['organiser'] ?? null) ? trim($_GET['organiser']) : '';
    if ($organiser !== '') {
        $where[] = $organiserSql . ' = ?';
        $params[] = $organiser;
        $filters['organiser'] = $organiser;
    }
    $status = $_GET['status'] ?? '';
    if (in_array($status, ['Pending', 'Approved', 'Rejected', 'Completed'], true)) {
        $where[] = 't.Status = ?';
        $params[] = $status;
        $filters['status'] = $status;
    }
    $from = 'Tournaments t LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId';

    return [
        'title' => 'Tournaments',
        'from' => $from,
        'select' => "t.Name, $organiserSql AS Organiser, t.TournamentDate, t.Location, t.Status,
            (SELECT COUNT(*) FROM Matches m WHERE m.TournamentId = t.TournamentId) AS Results,
            (SELECT COALESCE(SUM(p.PointsAwarded), 0) FROM PlayerPointsHistory p WHERE p.TournamentId = t.TournamentId) AS Points",
        'where' => $where,
        'params' => $params,
        'order' => 't.TournamentDate DESC, t.Name ASC',
        'columns' => [['name', 'Tournament'], ['organiser', 'Organiser'], ['date', 'Date'], ['location', 'Location'],
            ['status', 'Status'], ['results', 'Results'], ['points', 'Points awarded']],
        'filters' => $filters,
        'map' => function ($r) {
            return [
                'name' => plain($r['Name']),
                'organiser' => plain($r['Organiser']),
                'date' => kbfCsvDate($r['TournamentDate']),
                'location' => plain($r['Location']),
                'status' => (string) $r['Status'],
                'results' => (int) $r['Results'],
                'points' => (int) $r['Points'],
            ];
        },
        'summary' => function () use ($pdo, $from, $where, $params) {
            $stmt = $pdo->prepare("SELECT t.Status, COUNT(*) AS n FROM $from WHERE " . implode(' AND ', $where) . " GROUP BY t.Status");
            $stmt->execute($params);
            $parts = [];
            $total = 0;
            foreach ($stmt->fetchAll() as $r) {
                $parts[] = (int) $r['n'] . ' ' . strtolower((string) $r['Status']);
                $total += (int) $r['n'];
            }
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(p.PointsAwarded), 0) FROM PlayerPointsHistory p
                JOIN Tournaments t ON t.TournamentId = p.TournamentId
                LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
                WHERE " . implode(' AND ', $where));
            $stmt->execute($params);
            $points = (int) $stmt->fetchColumn();
            return ['type' => 'text', 'line' => $total . ' tournament' . ($total === 1 ? '' : 's')
                . ($parts ? ' (' . implode(', ', $parts) . ')' : '') . ', ' . number_format($points) . ' points awarded.'];
        },
        'options' => function () use ($pdo, $organiserSql) {
            $organisers = $pdo->query("
                SELECT DISTINCT $organiserSql AS Organiser
                FROM Tournaments t LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
                ORDER BY Organiser
            ")->fetchAll(PDO::FETCH_COLUMN);
            return [
                ['name' => 'season', 'label' => 'Season', 'choices' => seasonOptions($pdo, 'Tournaments', 'TournamentDate')],
                ['name' => 'organiser', 'label' => 'Organiser', 'choices' => array_merge([['value' => '', 'label' => 'All organisers']],
                    array_values(array_map(function ($o) { return ['value' => (string) $o, 'label' => plain($o)]; },
                        array_filter($organisers, function ($o) { return $o !== null && $o !== ''; }))))],
                ['name' => 'status', 'label' => 'Status', 'choices' => [
                    ['value' => '', 'label' => 'All statuses'], ['value' => 'Pending', 'label' => 'Pending'],
                    ['value' => 'Approved', 'label' => 'Approved'], ['value' => 'Completed', 'label' => 'Completed'],
                    ['value' => 'Rejected', 'label' => 'Rejected']]],
            ];
        },
    ];
}

function reportPoints($pdo) {
    $where = ['1 = 1'];
    $params = [];
    $filters = [];
    $season = seasonParam();
    require_once __DIR__ . '/points-rules.php';
    if ($season) {
        $where[] = 'pph.AwardedDate >= ? AND pph.AwardedDate < ?';
        $params[] = $season['from'];
        $params[] = $season['to'];
        $filters['season'] = $_GET['season'];
        // A season's points leave out career-only awards
        $where[] = kbfSeasonAwardSql($pdo, 'pph');
    }
    $seasonFlag = kbfHasSeasonFlag($pdo) ? 'pph.CountsTowardSeason' : '1';
    $grade = gradeIdParam();
    if ($grade) {
        $where[] = 'u.CategoryId = ?';
        $params[] = $grade;
        $filters['grade'] = $grade;
    }
    $tournament = filter_var($_GET['tournament'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($tournament) {
        $where[] = 'pph.TournamentId = ?';
        $params[] = $tournament;
        $filters['tournament'] = $tournament;
    }
    $from = 'PlayerPointsHistory pph
        LEFT JOIN Tournaments t ON t.TournamentId = pph.TournamentId
        LEFT JOIN Users u ON u.UserId = pph.PlayerId
        LEFT JOIN Categories c ON c.CategoryId = u.CategoryId';

    return [
        'title' => 'Points and results',
        'from' => $from,
        'select' => 'pph.AwardedDate, t.Name AS TournamentName, u.FirstName, u.LastName, u.PlayerId, c.ShortName,
            pph.Reason, pph.PointsAwarded, COALESCE(u.IsRemoved, 0) AS IsRemoved, ' . $seasonFlag . ' AS CountsTowardSeason',
        'where' => $where,
        'params' => $params,
        'order' => 'pph.AwardedDate DESC, t.Name ASC, u.LastName ASC',
        'columns' => [['date', 'Date'], ['tournament', 'Tournament'], ['player', 'Player'], ['kbfId', 'KBF ID'],
            ['grade', 'Current grade'], ['placing', 'Placing'], ['points', 'Points'], ['countsToward', 'Counts toward']],
        'filters' => $filters,
        'map' => function ($r) {
            // Removed at their request: points stay, shown as "Former member"
            $removed = (int) $r['IsRemoved'] === 1;
            return [
                'date' => kbfCsvDate($r['AwardedDate']),
                'tournament' => plain($r['TournamentName']),
                'player' => $removed ? 'Former member' : fullName($r['FirstName'], $r['LastName']),
                'kbfId' => $removed ? '' : (string) $r['PlayerId'],
                'grade' => $removed ? '' : plain($r['ShortName']),
                'placing' => plain($r['Reason']),
                'points' => (int) $r['PointsAwarded'],
                'countsToward' => (int) $r['CountsTowardSeason'] === 0 ? 'Career points only' : 'Season and career',
            ];
        },
        'summary' => function () use ($pdo, $from, $where, $params) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(pph.PointsAwarded), 0) AS total, COUNT(DISTINCT pph.PlayerId) AS players
                FROM $from WHERE " . implode(' AND ', $where));
            $stmt->execute($params);
            $r = $stmt->fetch();
            $players = (int) $r['players'];
            return ['type' => 'text', 'line' => number_format((int) $r['total']) . ' points awarded to ' . $players . ' player' . ($players === 1 ? '' : 's') . '.'];
        },
        'options' => function () use ($pdo) {
            $tournaments = $pdo->query("
                SELECT t.TournamentId, t.Name, t.TournamentDate FROM Tournaments t
                WHERE EXISTS (SELECT 1 FROM PlayerPointsHistory p WHERE p.TournamentId = t.TournamentId)
                ORDER BY t.TournamentDate DESC LIMIT 500
            ")->fetchAll();
            return [
                ['name' => 'season', 'label' => 'Season', 'choices' => seasonOptions($pdo, 'PlayerPointsHistory', 'AwardedDate')],
                ['name' => 'grade', 'label' => 'Grade', 'choices' => array_merge([['value' => '', 'label' => 'All grades']], gradeOptions($pdo))],
                ['name' => 'tournament', 'label' => 'Tournament', 'choices' => array_merge([['value' => '', 'label' => 'All tournaments']],
                    array_map(function ($t) { return ['value' => (string) $t['TournamentId'], 'label' => plain($t['Name']) . ' (' . kbfCsvDate($t['TournamentDate']) . ')']; }, $tournaments))],
            ];
        },
    ];
}

function reportGradeChanges($pdo) {
    $typeSql = "CASE WHEN rh.Reason LIKE 'Moved by an admin%' THEN 'moved'
                     WHEN nc.DisplayOrder < oc.DisplayOrder THEN 'promotion'
                     ELSE 'other' END";
    $where = ['1 = 1'];
    $params = [];
    $filters = [];
    $season = seasonParam();
    if ($season) {
        $where[] = 'rh.ChangeDate >= ? AND rh.ChangeDate < ?';
        $params[] = $season['from'];
        $params[] = $season['to'];
        $filters['season'] = $_GET['season'];
    }
    $grade = gradeIdParam();
    if ($grade) {
        $where[] = '(rh.NewCategoryId = ? OR rh.OldCategoryId = ?)';
        $params[] = $grade;
        $params[] = $grade;
        $filters['grade'] = $grade;
    }
    $type = $_GET['type'] ?? '';
    if (in_array($type, ['promotion', 'other', 'moved'], true)) {
        $where[] = "($typeSql) = ?";
        $params[] = $type;
        $filters['type'] = $type;
    }
    $from = 'PlayerRankingHistory rh
        LEFT JOIN Users u ON u.UserId = rh.PlayerId
        LEFT JOIN Categories oc ON oc.CategoryId = rh.OldCategoryId
        LEFT JOIN Categories nc ON nc.CategoryId = rh.NewCategoryId
        LEFT JOIN Users adm ON adm.UserId = rh.ChangedByAdminId';
    $typeNames = ['promotion' => 'Promotion', 'other' => 'Other change', 'moved' => 'Moved by an admin'];
    // Players removed at their request are left out
    $where[] = 'COALESCE(u.IsRemoved, 0) = 0';

    return [
        'title' => 'Grade changes',
        'from' => $from,
        'select' => "rh.ChangeDate, u.FirstName, u.LastName, u.PlayerId, oc.ShortName AS OldGrade, nc.ShortName AS NewGrade,
            ($typeSql) AS ChangeType, rh.Reason, rh.IsAutomatic, adm.FirstName AS AdmFirst, adm.LastName AS AdmLast",
        'where' => $where,
        'params' => $params,
        'order' => 'rh.ChangeDate DESC',
        'columns' => [['date', 'Date'], ['player', 'Player'], ['kbfId', 'KBF ID'], ['oldGrade', 'Old grade'],
            ['newGrade', 'New grade'], ['type', 'Type'], ['reason', 'Reason'], ['changedBy', 'Changed by']],
        'filters' => $filters,
        'map' => function ($r) use ($typeNames) {
            $by = fullName($r['AdmFirst'], $r['AdmLast']);
            return [
                'date' => kbfCsvDate($r['ChangeDate']),
                'player' => fullName($r['FirstName'], $r['LastName']),
                'kbfId' => (string) $r['PlayerId'],
                'oldGrade' => plain($r['OldGrade']),
                'newGrade' => plain($r['NewGrade']),
                'type' => $typeNames[$r['ChangeType']] ?? (string) $r['ChangeType'],
                'reason' => plain($r['Reason']),
                'changedBy' => $by !== '' ? $by : ((int) $r['IsAutomatic'] === 1 ? 'System' : ''),
            ];
        },
        'summary' => function () use ($pdo, $from, $where, $params, $typeSql, $typeNames) {
            $stmt = $pdo->prepare("SELECT ($typeSql) AS t, COUNT(*) AS n FROM $from WHERE " . implode(' AND ', $where) . " GROUP BY t");
            $stmt->execute($params);
            $parts = [];
            $total = 0;
            foreach ($stmt->fetchAll() as $r) {
                $parts[] = (int) $r['n'] . ' ' . strtolower($typeNames[$r['t']] ?? $r['t']);
                $total += (int) $r['n'];
            }
            return ['type' => 'text', 'line' => $total . ' grade change' . ($total === 1 ? '' : 's') . ($parts ? ': ' . implode(', ', $parts) : '') . '.'];
        },
        'options' => function () use ($pdo) {
            return [
                ['name' => 'season', 'label' => 'Season', 'choices' => seasonOptions($pdo, 'PlayerRankingHistory', 'ChangeDate')],
                ['name' => 'grade', 'label' => 'Grade', 'choices' => array_merge([['value' => '', 'label' => 'All grades']], gradeOptions($pdo, true))],
                ['name' => 'type', 'label' => 'Type', 'choices' => [
                    ['value' => '', 'label' => 'All types'], ['value' => 'promotion', 'label' => 'Promotion'],
                    ['value' => 'other', 'label' => 'Other change'], ['value' => 'moved', 'label' => 'Moved by an admin']]],
            ];
        },
    ];
}

function reportStaff($pdo) {
    $from = 'Users u';
    $where = ['(u.IsSuperAdmin = 1 OR EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId))', 'u.IsRemoved = 0'];
    $roleNames = ['MainAdmin' => 'Main Admin', 'ZonalAdmin' => 'Zonal Admin', 'Organiser' => 'Organiser'];

    return [
        'title' => 'Staff and access',
        'from' => $from,
        'select' => "u.FirstName, u.LastName, u.PlayerId, u.IsSuperAdmin, u.LastLoginDate, u.TwoFactorEnabledDate,
            (SELECT GROUP_CONCAT(sr.RoleKey ORDER BY sr.RoleKey SEPARATOR ',') FROM StaffRoles sr WHERE sr.UserId = u.UserId) AS Roles,
            (SELECT GROUP_CONCAT(p.Label ORDER BY p.SortOrder SEPARATOR ', ')
               FROM UserPermissions up JOIN Permissions p ON p.PermissionKey = up.PermissionKey
              WHERE up.UserId = u.UserId) AS PermissionLabels,
            (CASE WHEN " . KBF_CAN_SIGN_IN_SQL . " THEN 1 ELSE 0 END) AS CanSignIn",
        'where' => $where,
        'params' => [],
        'order' => 'u.IsSuperAdmin DESC, u.FirstName ASC, u.LastName ASC',
        'columns' => [['name', 'Name'], ['kbfId', 'KBF ID'], ['labels', 'Labels'], ['permissions', 'Permissions'],
            ['twoStep', '2-step'], ['canSignIn', 'Can sign in'], ['lastSignIn', 'Last sign-in']],
        'filters' => [],
        'map' => function ($r) use ($roleNames) {
            $labels = [];
            if ((int) $r['IsSuperAdmin'] === 1) {
                $labels[] = 'Super Admin';
            }
            foreach (array_filter(explode(',', (string) $r['Roles'])) as $role) {
                $labels[] = $roleNames[$role] ?? $role;
            }
            return [
                'name' => fullName($r['FirstName'], $r['LastName']),
                'kbfId' => (string) $r['PlayerId'],
                'labels' => implode(', ', $labels),
                'permissions' => (int) $r['IsSuperAdmin'] === 1 ? 'Everything' : (string) $r['PermissionLabels'],
                'twoStep' => $r['TwoFactorEnabledDate'] ? 'On' : 'Off',
                'canSignIn' => (int) $r['CanSignIn'] === 1 ? 'Yes' : 'No',
                'lastSignIn' => kbfCsvDate($r['LastLoginDate']),
            ];
        },
        'summary' => function () use ($pdo, $from, $where) {
            $r = $pdo->query("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN " . KBF_CAN_SIGN_IN_SQL . " THEN 1 ELSE 0 END) AS canSignIn,
                       SUM(u.TwoFactorEnabledDate IS NOT NULL) AS twoStep
                FROM $from WHERE " . implode(' AND ', $where))->fetch();
            return ['type' => 'text', 'line' => (int) $r['total'] . ' staff, ' . (int) $r['canSignIn'] . ' can sign in, '
                . (int) $r['twoStep'] . ' with 2-step verification on.'];
        },
        'options' => function () {
            return [];
        },
    ];
}

// ============================================
// RUN
// ============================================
$builders = [
    'players' => function () use ($pdo, $canContacts) { return reportPlayers($pdo, $canContacts); },
    'registrations' => function () use ($pdo) { return reportRegistrations($pdo); },
    'tournaments' => function () use ($pdo) { return reportTournaments($pdo); },
    'points' => function () use ($pdo) { return reportPoints($pdo); },
    'grades' => function () use ($pdo) { return reportGradeChanges($pdo); },
    'staff' => function () use ($pdo) { return reportStaff($pdo); },
];
if (!isset($builders[$reportKey])) {
    sendJSON(['success' => false, 'message' => 'Unknown report'], 400);
}

try {
    $r = $builders[$reportKey]();
    $whereSql = implode(' AND ', $r['where']);
    $baseSql = "SELECT {$r['select']} FROM {$r['from']} WHERE $whereSql ORDER BY {$r['order']}";

    // ---------- CSV download (streamed) ----------
    if ($download) {
        $out = kbfCsvStart($reportKey, array_map(function ($c) { return $c[1]; }, $r['columns']));
        $keys = array_map(function ($c) { return $c[0]; }, $r['columns']);
        $map = $r['map'];
        $rows = kbfStreamRows($pdo, $baseSql, $r['params'], function ($row) use ($out, $keys, $map) {
            $mapped = $map($row);
            kbfCsvRow($out, array_map(function ($k) use ($mapped) { return $mapped[$k] ?? ''; }, $keys));
        });
        fclose($out);

        $filterText = [];
        foreach ($r['filters'] as $k => $v) {
            $filterText[] = $k . '=' . $v;
        }
        auditLog('report_downloaded', 'report', null,
            'Report: ' . $r['title'] . '; filters: ' . ($filterText ? implode(', ', $filterText) : 'none') . '; rows: ' . $rows);
        exit;
    }

    // ---------- a page of rows ----------
    $offset = kbfOffsetParam();
    $stmt = $pdo->prepare($baseSql . ' LIMIT ' . (KBF_REPORT_PAGE_SIZE + 1) . ' OFFSET ' . $offset);
    $stmt->execute($r['params']);
    $rows = array_map($r['map'], $stmt->fetchAll());
    $hasMore = count($rows) > KBF_REPORT_PAGE_SIZE;
    if ($hasMore) {
        array_pop($rows);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$r['from']} WHERE $whereSql");
    $countStmt->execute($r['params']);

    $response = [
        'success' => true,
        'report' => $reportKey,
        'title' => $r['title'],
        'columns' => array_map(function ($c) { return ['key' => $c[0], 'label' => $c[1]]; }, $r['columns']),
        'rows' => $rows,
        'total' => (int) $countStmt->fetchColumn(),
        'hasMore' => $hasMore,
        'nextOffset' => $offset + count($rows),
        'contactDetails' => $reportKey === 'players' && $canContacts,
        'canDownload' => !isImpersonating(),
    ];
    if ($offset === 0) {
        $response['summary'] = $r['summary']();
        $response['options'] = $r['options']();
    }
    sendJSON($response);

} catch (PDOException $e) {
    error_log('control-reports.php (' . $reportKey . '): ' . $e->getMessage());
    if (headers_sent()) {
        exit;
    }
    sendJSON(['success' => false, 'message' => 'The report could not be made.'], 500);
}
?>
