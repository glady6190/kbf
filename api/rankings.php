<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/points-rules.php';

// ============================================
// GRADING (players.change_grades)
//
//   GET  ?action=summary            season card, tab counts, Eligible and
//                                   Close to promotion lists, grades, defaults
//                                   (also the default when no action is given)
//   GET  ?action=history&type=&from=&to=&page=
//                                   recent grade changes, 50 a page
//                                   type: promotion | down | across | moved
//   GET  ?action=meta               grades and tick defaults for the dialog
//   GET  ?action=search&q=          players for the Change a grade dialog
//   GET  ?action=player&id=         one player for the dialog
//   POST {userId, newCategoryId, reason, createAnnouncement, sendEmail, notes}
//                                   change a grade (promotion when the new
//                                   grade ranks higher); the email and news
//                                   post follow the ticks, which start from the
//                                   Notifications switches
//
// Order of checks: logged in (401), CSRF on writes (403),
// players.change_grades (403). Every change is written to AuditLog.
// ============================================

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}
requirePermission('players.change_grades');

const KBF_GRADING_HISTORY_PAGE = 50;
const KBF_GRADING_PHOTO_PATTERN = '#^/uploads/(profiles|registrations)/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#';

// ============================================
// HELPERS
// ============================================
function gPlain($value) {
    return $value === null ? '' : trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
}

// Active grades in rank order, with each grade's threshold and the grade above
// [CategoryId => ['id', 'shortName', 'name', 'rank', 'threshold', 'next' => id|null]]
function gGrades($pdo) {
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $out = [];
    $prev = null;
    foreach (kbfActiveGrades($pdo) as $i => $g) {
        $id = (int) $g['CategoryId'];
        $threshold = (int) $g['PromotionThreshold'];
        $out[$id] = [
            'id' => $id,
            'shortName' => gPlain($g['ShortName']),
            'name' => gPlain($g['Name']),
            'rank' => $i,
            'threshold' => $i === 0 ? null : ($threshold > 0 ? $threshold : KBF_DEFAULT_THRESHOLD),
            'next' => $prev,
        ];
        $prev = $id;
    }
    return $out;
}

function gCloseRange() {
    return kbfSettingInt('close_to_promotion_points', 150, 10, 1000);
}

// Season points this season, from awards that count toward the season and
// grade (career-only awards left out): [userId => ['finals' => n, 'events' => n]]
function gSeasonStats($pdo, array $userIds) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT pph.PlayerId,
               COUNT(DISTINCT pph.TournamentId) AS Events,
               COUNT(DISTINCT CASE WHEN m.RoundType = 'Final' THEN pph.MatchId END) AS Finals
        FROM PlayerPointsHistory pph
        JOIN Tournaments t ON t.TournamentId = pph.TournamentId
        LEFT JOIN Matches m ON m.MatchId = pph.MatchId
        WHERE pph.PlayerId IN ($in)
          AND t.TournamentDate >= ?
          AND t.TournamentDate > COALESCE(
                (SELECT DATE(MAX(rh.ChangeDate)) FROM PlayerRankingHistory rh WHERE rh.PlayerId = pph.PlayerId),
                '1000-01-01')
          AND " . kbfSeasonAwardSql($pdo, 'pph') . "
        GROUP BY pph.PlayerId
    ");
    $stmt->execute(array_merge($ids, [kbfCurrentSeasonStart($pdo)]));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['PlayerId']] = ['finals' => (int) $r['Finals'], 'events' => (int) $r['Events']];
    }
    return $out;
}

// One player row for the lists and the dialog
function gPlayer($pdo, array $r, array $stats = []) {
    $grades = gGrades($pdo);
    $gradeId = $r['CategoryId'] !== null ? (int) $r['CategoryId'] : null;
    $grade = $gradeId !== null ? ($grades[$gradeId] ?? null) : null;
    $next = ($grade && $grade['next'] !== null) ? $grades[$grade['next']] : null;
    $points = (int) $r['TotalPoints'];
    $threshold = $grade ? $grade['threshold'] : null;
    $uid = (int) $r['UserId'];
    $first = gPlain($r['FirstName']);
    $last = gPlain($r['LastName']);
    $photo = $r['ProfilePhoto'] ?? null;
    return [
        'userId' => $uid,
        'firstName' => $first,
        'name' => trim($first . ' ' . $last),
        'initials' => strtoupper(mb_substr($first, 0, 1, 'UTF-8') . mb_substr($last, 0, 1, 'UTF-8')),
        'kbfId' => (string) $r['PlayerId'],
        'city' => gPlain($r['Location'] ?? ''),
        'photo' => (is_string($photo) && preg_match(KBF_GRADING_PHOTO_PATTERN, $photo)) ? $photo : null,
        'gradeId' => $gradeId,
        'grade' => $grade ? $grade['shortName'] : gPlain($r['GradeShort'] ?? ''),
        'gradeName' => $grade ? $grade['name'] : gPlain($r['GradeName'] ?? ''),
        'nextGradeId' => $next ? $next['id'] : null,
        'nextGrade' => $next ? $next['shortName'] : null,
        'nextGradeName' => $next ? $next['name'] : null,
        'points' => $points,
        'threshold' => $threshold,
        'toGo' => $threshold !== null ? max(0, $threshold - $points) : null,
        'finals' => $stats[$uid]['finals'] ?? 0,
        'events' => $stats[$uid]['events'] ?? 0,
    ];
}

const KBF_GRADING_PLAYER_SELECT = "
    SELECT u.UserId, u.FirstName, u.LastName, u.PlayerId, u.Location, u.ProfilePhoto,
           u.CategoryId, u.TotalPoints, c.ShortName AS GradeShort, c.Name AS GradeName,
           COALESCE(pt.EligibleForPromotion, 0) AS EligibleForPromotion
    FROM Users u
    LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
    LEFT JOIN PlayerPerformanceTracker pt ON pt.PlayerId = u.UserId";

// Eligible for promotion: active, not removed, EligibleForPromotion = 1
function gEligible($pdo) {
    $stmt = $pdo->query(KBF_GRADING_PLAYER_SELECT . "
        WHERE u.IsActive = 1 AND COALESCE(u.IsRemoved, 0) = 0 AND pt.EligibleForPromotion = 1
        ORDER BY c.DisplayOrder ASC, u.TotalPoints DESC, u.LastName ASC, u.FirstName ASC
    ");
    $rows = $stmt->fetchAll();
    $stats = gSeasonStats($pdo, array_column($rows, 'UserId'));
    return array_map(function ($r) use ($pdo, $stats) { return gPlayer($pdo, $r, $stats); }, $rows);
}

// Close to promotion: active, not removed, not eligible, not in the top grade,
// season points at least their grade's threshold minus the range
function gClose($pdo) {
    $grades = gGrades($pdo);
    $range = gCloseRange();
    $conditions = [];
    $params = [];
    foreach ($grades as $g) {
        if ($g['threshold'] === null) {
            continue;   // top grade
        }
        $conditions[] = '(u.CategoryId = ? AND u.TotalPoints >= ?)';
        $params[] = $g['id'];
        $params[] = $g['threshold'] - $range;
    }
    if (!$conditions) {
        return [];
    }
    $stmt = $pdo->prepare(KBF_GRADING_PLAYER_SELECT . "
        WHERE u.IsActive = 1 AND COALESCE(u.IsRemoved, 0) = 0
          AND COALESCE(pt.EligibleForPromotion, 0) = 0
          AND (" . implode(' OR ', $conditions) . ")
        ORDER BY u.TotalPoints DESC, u.LastName ASC, u.FirstName ASC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $stats = gSeasonStats($pdo, array_column($rows, 'UserId'));
    return array_map(function ($r) use ($pdo, $stats) { return gPlayer($pdo, $r, $stats); }, $rows);
}

// Type of a grade change, as SQL on PlayerRankingHistory rh with oc, nc
const KBF_GRADING_TYPE_SQL = "CASE WHEN rh.Reason LIKE 'Moved by an admin%' THEN 'moved'
                                   WHEN nc.DisplayOrder < oc.DisplayOrder THEN 'promotion'
                                   WHEN nc.DisplayOrder > oc.DisplayOrder THEN 'down'
                                   ELSE 'across' END";
const KBF_GRADING_TYPE_NAMES = ['promotion' => 'Promotion', 'down' => 'Moving down', 'across' => 'Moving across', 'moved' => 'Moved by an admin'];

function gHistoryWhere() {
    $where = ['COALESCE(u.IsRemoved, 0) = 0'];
    $params = [];
    $type = $_GET['type'] ?? '';
    if (isset(KBF_GRADING_TYPE_NAMES[$type])) {
        $where[] = '(' . KBF_GRADING_TYPE_SQL . ') = ?';
        $params[] = $type;
    }
    foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
        $value = (string) ($_GET[$key] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $where[] = 'rh.ChangeDate ' . $op . ' ?';
            $params[] = $key === 'to' ? date('Y-m-d', strtotime($value . ' +1 day')) : $value;
        }
    }
    return [$where, $params];
}

function gHistory($pdo, $page) {
    list($where, $params) = gHistoryWhere();
    $from = "PlayerRankingHistory rh
        LEFT JOIN Users u ON u.UserId = rh.PlayerId
        LEFT JOIN Categories oc ON oc.CategoryId = rh.OldCategoryId
        LEFT JOIN Categories nc ON nc.CategoryId = rh.NewCategoryId
        LEFT JOIN Users adm ON adm.UserId = rh.ChangedByAdminId";
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $from WHERE $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $offset = ($page - 1) * KBF_GRADING_HISTORY_PAGE;
    $stmt = $pdo->prepare("
        SELECT rh.ChangeDate, rh.PlayerId, u.FirstName, u.LastName, u.PlayerId AS KbfId,
               oc.ShortName AS OldGrade, nc.ShortName AS NewGrade, rh.Reason, rh.IsAutomatic,
               (" . KBF_GRADING_TYPE_SQL . ") AS ChangeType,
               adm.FirstName AS AdmFirst, adm.LastName AS AdmLast
        FROM $from
        WHERE $whereSql
        ORDER BY rh.ChangeDate DESC
        LIMIT " . KBF_GRADING_HISTORY_PAGE . " OFFSET " . (int) $offset
    );
    $stmt->execute($params);
    $rows = array_map(function ($r) {
        $by = trim(gPlain($r['AdmFirst']) . ' ' . gPlain($r['AdmLast']));
        return [
            'date' => (string) $r['ChangeDate'],
            'userId' => (int) $r['PlayerId'],
            'player' => trim(gPlain($r['FirstName']) . ' ' . gPlain($r['LastName'])),
            'kbfId' => (string) $r['KbfId'],
            'oldGrade' => gPlain($r['OldGrade']),
            'newGrade' => gPlain($r['NewGrade']),
            'type' => (string) $r['ChangeType'],
            'typeLabel' => KBF_GRADING_TYPE_NAMES[$r['ChangeType']] ?? (string) $r['ChangeType'],
            'reason' => gPlain($r['Reason']),
            'changedBy' => $by !== '' ? $by : ((int) $r['IsAutomatic'] === 1 ? 'System' : ''),
        ];
    }, $stmt->fetchAll());
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / KBF_GRADING_HISTORY_PAGE))];
}

// Person email switch and news switch of an event
function gSwitch($pdo, $eventKey, $channel) {
    $s = kbfNotifyEventSettings($pdo, $eventKey);
    return [
        'applies' => (bool) ($s && $s[$channel]['applies']),
        'on' => (bool) ($s && kbfNotifyChannelOn($s, $channel)),
        'locked' => (bool) ($s && $s['locked']),
    ];
}

// "Promotion at 300 points" or "300 to 400 points" when grades differ
function gThresholdLabel($pdo) {
    $values = [];
    foreach (gGrades($pdo) as $g) {
        if ($g['threshold'] !== null) {
            $values[] = $g['threshold'];
        }
    }
    if (!$values) {
        return ['min' => null, 'label' => null];
    }
    $min = min($values);
    $max = max($values);
    return ['min' => $min, 'label' => $min === $max ? number_format($min) : number_format($min) . ' to ' . number_format($max)];
}

// ============================================
// GET
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'summary';

    try {
        if ($action === 'summary') {
            $eligible = gEligible($pdo);
            $close = gClose($pdo);
            $plan = kbfSeasonPlan($pdo, kbfSeasonSettings($pdo), null, false);
            $resetTs = strtotime($plan['nextResetDate']);
            $days = $resetTs === false ? null : max(0, (int) ceil(($resetTs - strtotime(date('Y-m-d'))) / 86400));
            $threshold = gThresholdLabel($pdo);

            $stmt = $pdo->query("
                SELECT COUNT(*) FROM PlayerRankingHistory rh
                LEFT JOIN Users u ON u.UserId = rh.PlayerId
                WHERE COALESCE(u.IsRemoved, 0) = 0
            ");
            $historyTotal = (int) $stmt->fetchColumn();

            $promotedEmail = gSwitch($pdo, 'promoted', 'person');
            $changedEmail = gSwitch($pdo, 'grade_changed', 'person');
            $promotedNews = gSwitch($pdo, 'promoted', 'news');

            sendJSON([
                'success' => true,
                'season' => [
                    'name' => $plan['season']['name'],
                    'threshold' => $threshold['label'],
                    'resetLabel' => kbfDateLabel($plan['nextResetDate']),
                    'resetDays' => $days,
                ],
                'closeRange' => gCloseRange(),
                'counts' => ['eligible' => count($eligible), 'close' => count($close), 'history' => $historyTotal],
                'eligible' => $eligible,
                'close' => $close,
                'grades' => array_values(array_map(function ($g) {
                    return ['id' => $g['id'], 'shortName' => $g['shortName'], 'name' => $g['name'], 'rank' => $g['rank'], 'threshold' => $g['threshold']];
                }, gGrades($pdo))),
                'defaults' => [
                    'promotionEmail' => $promotedEmail,
                    'changeEmail' => $changedEmail,
                    'promotionNews' => $promotedNews,
                ],
                // Kept for older callers
                'promotionNewsDefault' => $promotedNews['on'],
            ]);
        }

        // Grades and tick defaults for the Change a grade dialog (js/grade-change.js)
        if ($action === 'meta') {
            sendJSON([
                'success' => true,
                'grades' => array_values(array_map(function ($g) {
                    return ['id' => $g['id'], 'shortName' => $g['shortName'], 'name' => $g['name'], 'rank' => $g['rank'], 'threshold' => $g['threshold']];
                }, gGrades($pdo))),
                'defaults' => [
                    'promotionEmail' => gSwitch($pdo, 'promoted', 'person'),
                    'changeEmail' => gSwitch($pdo, 'grade_changed', 'person'),
                    'promotionNews' => gSwitch($pdo, 'promoted', 'news'),
                ],
            ]);
        }

        if ($action === 'history') {
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            sendJSON(array_merge(['success' => true], gHistory($pdo, $page)));
        }

        if ($action === 'search') {
            $q = trim((string) ($_GET['q'] ?? ''));
            if (mb_strlen($q, 'UTF-8') < 2) {
                sendJSON(['success' => true, 'players' => []]);
            }
            $q = mb_substr($q, 0, 100, 'UTF-8');
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $stmt = $pdo->prepare(KBF_GRADING_PLAYER_SELECT . "
                WHERE u.IsActive = 1 AND COALESCE(u.IsRemoved, 0) = 0 AND u.CategoryId IS NOT NULL
                  AND (CONCAT(u.FirstName, ' ', u.LastName) LIKE ? OR u.PlayerId LIKE ?)
                ORDER BY u.FirstName ASC, u.LastName ASC
                LIMIT 10
            ");
            $stmt->execute([$like, $like]);
            $players = array_map(function ($r) use ($pdo) { return gPlayer($pdo, $r); }, $stmt->fetchAll());
            sendJSON(['success' => true, 'players' => $players]);
        }

        if ($action === 'player') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                sendJSON(['success' => false, 'message' => 'Invalid player'], 400);
            }
            $stmt = $pdo->prepare(KBF_GRADING_PLAYER_SELECT . "
                WHERE u.UserId = ? AND u.IsActive = 1 AND COALESCE(u.IsRemoved, 0) = 0
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJSON(['success' => false, 'message' => 'This player is not active, so their grade cannot be changed.'], 404);
            }
            sendJSON(['success' => true, 'player' => gPlayer($pdo, $row)]);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);
    } catch (PDOException $e) {
        error_log('Database error in rankings.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST - change a grade (promotion or other change)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['userId']) || !isset($data['newCategoryId']) || !isset($data['reason'])) {
        sendJSON(['success' => false, 'message' => 'Player, new grade and reason are required'], 400);
    }
    kbfRequireScalarFields($data);

    $userId = filter_var($data['userId'], FILTER_VALIDATE_INT);
    $newCategoryId = filter_var($data['newCategoryId'], FILTER_VALIDATE_INT);
    // Stored as typed (no tags); escaped when shown
    $reason = kbfCleanText((string) $data['reason'], true);
    $createAnnouncement = !empty($data['createAnnouncement']);
    // null: follow the Notifications switch; true or false: the admin's tick
    $sendEmail = array_key_exists('sendEmail', $data) && $data['sendEmail'] !== null ? (bool) $data['sendEmail'] : null;

    if (!$userId || !$newCategoryId) {
        sendJSON(['success' => false, 'message' => 'Invalid player or grade'], 400);
    }
    if (!isset(gGrades($pdo)[$newCategoryId])) {
        sendJSON(['success' => false, 'message' => 'Choose one of the current grades.'], 400);
    }
    $plainLength = kbfTextLength($reason);
    if ($plainLength < 3 || $plainLength > 500) {
        sendJSON(['success' => false, 'message' => 'The reason must be 3 to 500 characters.'], 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT CategoryId FROM Users WHERE UserId = ? AND IsActive = 1 AND COALESCE(IsRemoved, 0) = 0 FOR UPDATE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Player not found'], 404);
        }

        // Grade changes apply to anyone who competes under the grading system,
        // staff included (they are players too).
        if ((int) $user['CategoryId'] === (int) $newCategoryId) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'The player is already in this grade'], 400);
        }

        // Season points start again from 0 in the new grade; awards from
        // tournaments on or before today count toward career points only
        // (api/points-rules.php). Every award that was counted is marked as
        // no longer counted, in this transaction.
        require_once __DIR__ . '/points-rules.php';
        kbfApplyGradeChangePoints($pdo, $userId, $newCategoryId);

        $stmt = $pdo->prepare("
            INSERT INTO PlayerRankingHistory
            (PlayerId, OldCategoryId, NewCategoryId, Reason, IsAutomatic, ChangedByAdminId, Notes)
            VALUES (?, ?, ?, ?, 0, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $user['CategoryId'],
            $newCategoryId,
            $reason,
            $_SESSION['user_id'],
            isset($data['notes']) && $data['notes'] !== null ? mb_substr(kbfCleanText((string) $data['notes'], true), 0, 2000) : null
        ]);

        $stmt = $pdo->prepare("
            UPDATE PlayerPerformanceTracker
            SET CategoryId = ?, EligibleForPromotion = 0, FlaggedForDemotion = 0
            WHERE PlayerId = ?
        ");
        $stmt->execute([$newCategoryId, $userId]);

        // Promotion is by rank (DisplayOrder), not by CategoryId
        $stmt = $pdo->prepare("
            SELECT u.FirstName, u.LastName, u.PlayerId, u.ProfilePhoto, u.YearOfBirth,
                   old_cat.Name as OldCategoryName, new_cat.Name as NewCategoryName,
                   old_cat.ShortName as OldShort, new_cat.ShortName as NewShort,
                   old_cat.DisplayOrder as OldDisplayOrder, new_cat.DisplayOrder as NewDisplayOrder
            FROM Users u
            LEFT JOIN Categories old_cat ON old_cat.CategoryId = ?
            JOIN Categories new_cat ON new_cat.CategoryId = ?
            WHERE u.UserId = ?
        ");
        $stmt->execute([$user['CategoryId'], $newCategoryId, $userId]);
        $playerData = $stmt->fetch();

        $isPromotion = $playerData && $playerData['OldDisplayOrder'] !== null
            && (int) $playerData['NewDisplayOrder'] < (int) $playerData['OldDisplayOrder'];

        $pdo->commit();

        // Stats for this player in the new grade, after the commit
        require_once __DIR__ . '/player-stats-calculator.php';
        kbfRecalculatePlayerStats($pdo, [$userId]);

        $oldLabel = $playerData && $playerData['OldShort'] !== null ? gPlain($playerData['OldShort']) : 'none';
        $newLabel = $playerData ? gPlain($playerData['NewShort']) : (string) $newCategoryId;
        auditLog('grade_changed', 'user', $userId,
            ($isPromotion ? 'Promoted' : 'Grade changed') . ' from ' . $oldLabel . ' to ' . $newLabel
            . '; reason: ' . html_entity_decode($reason, ENT_QUOTES, 'UTF-8')
            . '; email: ' . ($sendEmail === null ? 'as set in Notifications' : ($sendEmail ? 'yes' : 'no'))
            . ($isPromotion ? '; news post: ' . ($createAnnouncement ? 'yes' : 'no') : ''));

        // Notifications after the commit, so a slow or failed email can never
        // delay or undo the grade change
        kbfNotifyFlush();

        $responseMessage = $isPromotion ? 'Promoted to ' . $newLabel . '.' : 'Moved to ' . $newLabel . '.';
        $emailStatus = null;
        if ($playerData) {
            $plainReason = html_entity_decode($reason, ENT_QUOTES, 'UTF-8');
            $firstName = gPlain($playerData['FirstName']);
            $lastName = gPlain($playerData['LastName']);
            $context = [
                'person' => (int) $userId,
                'values' => [
                    'kbf_id' => (string) $playerData['PlayerId'],
                    'old_grade' => (string) ($playerData['OldCategoryName'] ?? ''),
                    'new_grade' => (string) $playerData['NewCategoryName'],
                    'reason' => $plainReason,
                ],
                'relatedType' => 'user',
                'relatedId' => (int) $userId,
            ];
            if ($sendEmail === true) {
                $context['forcePerson'] = true;
            } elseif ($sendEmail === false) {
                $context['skipPerson'] = true;
            }

            if ($isPromotion) {
                $context['news'] = [
                    'create' => $createAnnouncement,
                    'title' => "Player Promotion: {$firstName} {$lastName}",
                    'content' => "Congratulations to {$firstName} {$lastName} (Player ID: {$playerData['PlayerId']}) on their promotion!\n\n"
                        . "Category Change: {$playerData['OldCategoryName']} → {$playerData['NewCategoryName']}\n\n"
                        . "Well done on achieving this milestone! Keep up the excellent performance!",
                    // Never an under-18 player's photo on a public news post
                    'image' => (!empty($playerData['ProfilePhoto']) && !kbfIsUnder18($playerData['YearOfBirth'] ?? null))
                        ? $playerData['ProfilePhoto'] : null,
                    'authorId' => (int) $_SESSION['user_id'],
                ];
                $results = notify('promoted', $context);
            } else {
                $results = notify('grade_changed', $context);
            }

            $emailStatus = $results['person'] ?? null;
            if (in_array($emailStatus, ['sent', 'waiting', 'failed', 'none'], true)) {
                $responseMessage .= ' ' . kbfNotifyOutcomeText($results, 'person', $isPromotion ? 'The promotion email' : 'The email');
            }
            if (is_int($results['news'] ?? null)) {
                $responseMessage .= ' The news post is up.';
            }
        }

        sendJSON([
            'success' => true,
            'message' => $responseMessage,
            'emailStatus' => $emailStatus,
            'isPromotion' => $isPromotion,
        ]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        kbfNotifyDiscard();
        error_log('Ranking update error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
?>
