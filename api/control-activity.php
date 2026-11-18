<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/report-helpers.php';

// ============================================
// CONTROL CENTRE: ACTIVITY LOG (control.activity_log)
//
//   GET ?offset=0&person=&action=&range=&from=&to=&q=
//        one list of AuditLog and JobRuns entries (jobs shown as "System"),
//        newest first, 50 at a time; with offset 0 also the filter choices
//   GET ...&download=1   the filtered list as CSV (streamed)
//
//   person   a user id, or "system"
//   action   an action key (see KBF_ACTION_NAMES in api/report-helpers.php)
//   range    today | 7 | 30 | custom (with from and to, YYYY-MM-DD)
//   q        search in the details text
//   about    "people" (changes to players and staff) or a user id (changes
//            to that one person), used by the People page links
//
// The internet address is returned only to Super Admins. Reading is allowed
// while impersonating; downloads are not. Every download is written to
// AuditLog as report_downloaded.
// ============================================

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
requirePermission('control.activity_log');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

$download = !empty($_GET['download']);
if ($download) {
    requireNotImpersonating();
}
$showAddress = isSuperAdmin();

// ---------- the combined list ----------
function jobRunsReady($pdo) {
    try {
        return (int) $pdo->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'JobRuns'
        ")->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

$withJobs = jobRunsReady($pdo);
$entriesSql = "
    SELECT 'audit' AS Source, a.AuditId AS EntryId, a.CreatedDate AS At, a.ActorUserId AS Actor,
           a.ActingAsUserId AS ActingAs, a.Action AS Action, a.Details AS Details, a.IpAddress AS Ip,
           a.TargetType AS TType, a.TargetId AS TId
    FROM AuditLog a"
    . ($withJobs ? "
    UNION ALL
    SELECT 'job', j.Id, j.StartedDate, 0, NULL, CONCAT('job:', j.JobName),
           CONCAT(CASE WHEN j.Status = 'error' THEN 'Error. ' ELSE '' END, COALESCE(j.Summary, ''),
                  CASE WHEN j.FinishedDate IS NULL THEN ' (did not finish)' ELSE '' END),
           '', NULL, NULL
    FROM JobRuns j" : '');

// ---------- filters ----------
$where = ['1 = 1'];
$params = [];
$filters = [];

$person = $_GET['person'] ?? '';
if ($person === 'system') {
    $where[] = "(e.Source = 'job' OR e.Actor = 0)";
    $filters['person'] = 'System';
} elseif ($person !== '' && ($pid = filter_var($person, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
    $where[] = '(e.Actor = ? OR e.ActingAs = ?)';
    $params[] = $pid;
    $params[] = $pid;
    $filters['person'] = $pid;
}

// Changes about people (players and staff), or about one person
$about = $_GET['about'] ?? '';
if ($about === 'people') {
    $where[] = "e.TType IN ('user', 'registration')";
    $filters['about'] = 'people';
} elseif ($about !== '' && ($aid = filter_var($about, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
    $where[] = "(e.TType = 'user' AND e.TId = ?)";
    $params[] = $aid;
    $filters['about'] = $aid;
}

$action = is_string($_GET['action'] ?? null) ? trim($_GET['action']) : '';
if ($action !== '' && preg_match('/^[a-z0-9_:\-]{1,70}$/', $action)) {
    $where[] = 'e.Action = ?';
    $params[] = $action;
    $filters['action'] = $action;
}

$range = $_GET['range'] ?? '';
if ($range === 'today') {
    $where[] = 'e.At >= CURDATE()';
    $filters['date'] = 'today';
} elseif ($range === '7' || $range === '30') {
    $where[] = 'e.At >= DATE_SUB(CURDATE(), INTERVAL ' . ((int) $range - 1) . ' DAY)';
    $filters['date'] = 'last ' . $range . ' days';
} elseif ($range === 'custom') {
    $from = kbfDateParam($_GET['from'] ?? null);
    $to = kbfDateParam($_GET['to'] ?? null);
    if ($from) {
        $where[] = 'e.At >= ?';
        $params[] = $from . ' 00:00:00';
        $filters['from'] = $from;
    }
    if ($to) {
        $where[] = 'e.At < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $to;
        $filters['to'] = $to;
    }
}

$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if ($q !== '') {
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
    $where[] = "e.Details LIKE ? ESCAPE '\\\\'";
    $params[] = '%' . kbfLikeEscape($q) . '%';
    $filters['search'] = $q;
}

$listSql = "
    SELECT e.Source, e.EntryId, e.At, e.Actor, e.ActingAs, e.Action, e.Details, e.Ip,
           ua.FirstName AS ActorFirst, ua.LastName AS ActorLast,
           ub.FirstName AS AsFirst, ub.LastName AS AsLast
    FROM ($entriesSql) e
    LEFT JOIN Users ua ON ua.UserId = e.Actor
    LEFT JOIN Users ub ON ub.UserId = e.ActingAs
    WHERE " . implode(' AND ', $where) . "
    ORDER BY e.At DESC, e.EntryId DESC";

function personName($first, $last) {
    return trim(html_entity_decode($first . ' ' . $last, ENT_QUOTES, 'UTF-8'));
}

// One entry in plain words
function entryRow(array $r, $showAddress) {
    $isSystem = $r['Source'] === 'job' || (int) $r['Actor'] === 0;
    $who = $isSystem ? 'System' : personName($r['ActorFirst'], $r['ActorLast']);
    if (!$isSystem && $who === '') {
        $who = 'Removed user #' . (int) $r['Actor'];
    }
    $as = '';
    if (!empty($r['ActingAs'])) {
        $as = personName($r['AsFirst'], $r['AsLast']);
        if ($as === '') {
            $as = 'user #' . (int) $r['ActingAs'];
        }
    }
    $row = [
        'when' => (string) $r['At'],
        'who' => $who,
        'system' => $isSystem,
        'actingAs' => $as,
        'action' => (string) $r['Action'],
        'what' => kbfActionName($r['Action']),
        'details' => html_entity_decode((string) $r['Details'], ENT_QUOTES, 'UTF-8'),
    ];
    if ($showAddress) {
        $row['address'] = $r['Source'] === 'job' ? '' : (string) $r['Ip'];
    }
    return $row;
}

try {
    // ---------- CSV download (streamed) ----------
    if ($download) {
        $headings = ['When', 'Who', 'Acting as', 'What', 'Details'];
        if ($showAddress) {
            $headings[] = 'Address';
        }
        $out = kbfCsvStart('activity-log', $headings);
        $rows = kbfStreamRows($pdo, $listSql, $params, function ($r) use ($out, $showAddress) {
            $e = entryRow($r, $showAddress);
            $cells = [$e['when'], $e['who'], $e['actingAs'], $e['what'], $e['details']];
            if ($showAddress) {
                $cells[] = $e['address'];
            }
            kbfCsvRow($out, $cells);
        });
        fclose($out);

        $filterText = [];
        foreach ($filters as $k => $v) {
            $filterText[] = $k . '=' . $v;
        }
        auditLog('report_downloaded', 'report', null,
            'Report: Activity log; filters: ' . ($filterText ? implode(', ', $filterText) : 'none') . '; rows: ' . $rows);
        exit;
    }

    // ---------- a page ----------
    $offset = kbfOffsetParam();
    $stmt = $pdo->prepare($listSql . ' LIMIT ' . (KBF_REPORT_PAGE_SIZE + 1) . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $entries = array_map(function ($r) use ($showAddress) { return entryRow($r, $showAddress); }, $stmt->fetchAll());
    $hasMore = count($entries) > KBF_REPORT_PAGE_SIZE;
    if ($hasMore) {
        array_pop($entries);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($entriesSql) e WHERE " . implode(' AND ', $where));
    $countStmt->execute($params);

    $response = [
        'success' => true,
        'entries' => $entries,
        'total' => (int) $countStmt->fetchColumn(),
        'hasMore' => $hasMore,
        'nextOffset' => $offset + count($entries),
        'showAddress' => $showAddress,
        'canDownload' => !isImpersonating(),
    ];

    if ($offset === 0) {
        // People who appear in the log
        $people = $pdo->query("
            SELECT DISTINCT u.UserId, u.FirstName, u.LastName
            FROM Users u
            WHERE u.UserId IN (SELECT ActorUserId FROM AuditLog WHERE ActorUserId > 0)
               OR u.UserId IN (SELECT ActingAsUserId FROM AuditLog WHERE ActingAsUserId IS NOT NULL)
            ORDER BY u.FirstName, u.LastName
        ")->fetchAll();
        $personChoices = [['value' => '', 'label' => 'Everyone'], ['value' => 'system', 'label' => 'System']];
        foreach ($people as $p) {
            $personChoices[] = ['value' => (string) $p['UserId'], 'label' => personName($p['FirstName'], $p['LastName'])];
        }

        // Actions in the log, by friendly name
        $keys = $pdo->query("SELECT DISTINCT Action FROM AuditLog")->fetchAll(PDO::FETCH_COLUMN);
        if ($withJobs) {
            foreach ($pdo->query("SELECT DISTINCT JobName FROM JobRuns")->fetchAll(PDO::FETCH_COLUMN) as $job) {
                $keys[] = 'job:' . $job;
            }
        }
        $actionChoices = array_map(function ($k) { return ['value' => (string) $k, 'label' => kbfActionName($k)]; }, array_unique($keys));
        usort($actionChoices, function ($a, $b) { return strcasecmp($a['label'], $b['label']); });
        array_unshift($actionChoices, ['value' => '', 'label' => 'All actions']);

        $response['options'] = ['people' => $personChoices, 'actions' => $actionChoices];
        $response['retentionDays'] = kbfSettingInt('audit_log_retention_days', 730, 90, 3650);
    }
    sendJSON($response);

} catch (PDOException $e) {
    error_log('control-activity.php: ' . $e->getMessage());
    if (headers_sent()) {
        exit;
    }
    sendJSON(['success' => false, 'message' => 'The activity log could not be read.'], 500);
}
?>
