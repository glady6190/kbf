<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/report-helpers.php';

// ============================================
// CONTROL CENTRE: ACTIVITY LOG (control.activity_log)
//
//   GET ?offset=0&person=&action=&range=&from=&to=&q=
//        one list of AuditLog and JobRuns entries (jobs shown as "System"),
//        newest first, 50 at a time; with offset 0 also the filter choices.
//        total: the exact count only on the first page of a list filtered by
//        date, person, action or about, otherwise null (use hasMore)
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

// The details text of a job run, as shown in the list
const KBF_JOB_DETAILS_SQL = "CONCAT(CASE WHEN j.Status = 'error' THEN 'Error. ' ELSE '' END, COALESCE(j.Summary, ''),
                  CASE WHEN j.FinishedDate IS NULL THEN ' (did not finish)' ELSE '' END)";

// ---------- filters ----------
// Each filter is applied inside each half (AuditLog, JobRuns) so the
// database narrows and sorts each table on its own indexes (PERF-09).
$auditWhere = ['1 = 1'];
$auditParams = [];
$jobWhere = ['1 = 1'];
$jobParams = [];
$includeJobs = $withJobs;
$filters = [];
$indexedFilter = false;

$person = $_GET['person'] ?? '';
if ($person === 'system') {
    $auditWhere[] = 'a.ActorUserId = 0';
    $filters['person'] = 'System';
    $indexedFilter = true;
} elseif ($person !== '' && ($pid = filter_var($person, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
    $auditWhere[] = '(a.ActorUserId = ? OR a.ActingAsUserId = ?)';
    $auditParams[] = $pid;
    $auditParams[] = $pid;
    $includeJobs = false;   // jobs have no person
    $filters['person'] = $pid;
    $indexedFilter = true;
}

// Changes about people (players and staff), or about one person
$about = $_GET['about'] ?? '';
if ($about === 'people') {
    $auditWhere[] = "a.TargetType IN ('user', 'registration')";
    $includeJobs = false;
    $filters['about'] = 'people';
    $indexedFilter = true;
} elseif ($about !== '' && ($aid = filter_var($about, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
    $auditWhere[] = "(a.TargetType = 'user' AND a.TargetId = ?)";
    $auditParams[] = $aid;
    $includeJobs = false;
    $filters['about'] = $aid;
    $indexedFilter = true;
}

$action = is_string($_GET['action'] ?? null) ? trim($_GET['action']) : '';
if ($action !== '' && preg_match('/^[a-z0-9_:\-]{1,70}$/', $action)) {
    $auditWhere[] = 'a.Action = ?';
    $auditParams[] = $action;
    if (strpos($action, 'job:') === 0) {
        $jobWhere[] = 'j.JobName = ?';
        $jobParams[] = substr($action, 4);
    } else {
        $includeJobs = false;
    }
    $filters['action'] = $action;
    $indexedFilter = true;
}

$range = $_GET['range'] ?? '';
$addDate = function ($sqlTail, $param = null) use (&$auditWhere, &$auditParams, &$jobWhere, &$jobParams) {
    $auditWhere[] = 'a.CreatedDate ' . $sqlTail;
    $jobWhere[] = 'j.StartedDate ' . $sqlTail;
    if ($param !== null) {
        $auditParams[] = $param;
        $jobParams[] = $param;
    }
};
if ($range === 'today') {
    $addDate('>= CURDATE()');
    $filters['date'] = 'today';
    $indexedFilter = true;
} elseif ($range === '7' || $range === '30') {
    $addDate('>= DATE_SUB(CURDATE(), INTERVAL ' . ((int) $range - 1) . ' DAY)');
    $filters['date'] = 'last ' . $range . ' days';
    $indexedFilter = true;
} elseif ($range === 'custom') {
    $from = kbfDateParam($_GET['from'] ?? null);
    $to = kbfDateParam($_GET['to'] ?? null);
    if ($from) {
        $addDate('>= ?', $from . ' 00:00:00');
        $filters['from'] = $from;
        $indexedFilter = true;
    }
    if ($to) {
        $addDate('< DATE_ADD(?, INTERVAL 1 DAY)', $to);
        $filters['to'] = $to;
        $indexedFilter = true;
    }
}

$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if ($q !== '') {
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
    $like = '%' . kbfLikeEscape($q) . '%';
    $auditWhere[] = "a.Details LIKE ? ESCAPE '\\\\'";
    $auditParams[] = $like;
    $jobWhere[] = KBF_JOB_DETAILS_SQL . " LIKE ? ESCAPE '\\\\'";
    $jobParams[] = $like;
    $filters['search'] = $q;
}

// The two halves, each filtered (and, for a page, sorted and limited) on
// its own; $limit null = every matching row (CSV download)
function activityEntriesSql(array $auditWhere, array $jobWhere, $includeJobs, $limit = null) {
    $tail = $limit === null ? '' : ' LIMIT ' . (int) $limit;
    $sql = "(
        SELECT 'audit' AS Source, a.AuditId AS EntryId, a.CreatedDate AS At, a.ActorUserId AS Actor,
               a.ActingAsUserId AS ActingAs, a.Action AS Action, a.Details AS Details, a.IpAddress AS Ip
        FROM AuditLog a
        WHERE " . implode(' AND ', $auditWhere)
        . ($limit === null ? '' : ' ORDER BY a.CreatedDate DESC, a.AuditId DESC') . $tail . "
    )";
    if ($includeJobs) {
        $sql .= "
    UNION ALL
    (
        SELECT 'job', j.Id, j.StartedDate, 0, NULL, CONCAT('job:', j.JobName),
               " . KBF_JOB_DETAILS_SQL . ",
               ''
        FROM JobRuns j
        WHERE " . implode(' AND ', $jobWhere)
        . ($limit === null ? '' : ' ORDER BY j.StartedDate DESC, j.Id DESC') . $tail . "
    )";
    }
    return $sql;
}

function activityListSql($entriesSql) {
    return "
    SELECT e.Source, e.EntryId, e.At, e.Actor, e.ActingAs, e.Action, e.Details, e.Ip,
           ua.FirstName AS ActorFirst, ua.LastName AS ActorLast,
           ub.FirstName AS AsFirst, ub.LastName AS AsLast
    FROM ($entriesSql) e
    LEFT JOIN Users ua ON ua.UserId = e.Actor
    LEFT JOIN Users ub ON ub.UserId = e.ActingAs
    ORDER BY e.At DESC, e.EntryId DESC";
}

$params = array_merge($auditParams, $includeJobs ? $jobParams : []);

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
        $listSql = activityListSql(activityEntriesSql($auditWhere, $jobWhere, $includeJobs));
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
    // Each half sorted and limited to offset + page + 1 rows, then merged
    $offset = min(kbfOffsetParam(), 100000);
    $perHalf = $offset + KBF_REPORT_PAGE_SIZE + 1;
    $listSql = activityListSql(activityEntriesSql($auditWhere, $jobWhere, $includeJobs, $perHalf));
    $stmt = $pdo->prepare($listSql . ' LIMIT ' . (KBF_REPORT_PAGE_SIZE + 1) . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $entries = array_map(function ($r) use ($showAddress) { return entryRow($r, $showAddress); }, $stmt->fetchAll());
    $hasMore = count($entries) > KBF_REPORT_PAGE_SIZE;
    if ($hasMore) {
        array_pop($entries);
    }

    // The exact total only for the first page of a filtered list (date,
    // person, action or about), counted in each half; otherwise null and
    // the page uses hasMore
    $total = null;
    if ($offset === 0 && $indexedFilter) {
        $countSql = "SELECT (SELECT COUNT(*) FROM AuditLog a WHERE " . implode(' AND ', $auditWhere) . ")"
            . ($includeJobs ? " + (SELECT COUNT(*) FROM JobRuns j WHERE " . implode(' AND ', $jobWhere) . ")" : '');
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
    } elseif ($offset === 0 && !$hasMore) {
        $total = count($entries);
    }

    $response = [
        'success' => true,
        'entries' => $entries,
        'total' => $total,
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
