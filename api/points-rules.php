<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
// ============================================
// KBF-UK: WHEN TOURNAMENT POINTS COUNT TOWARD THE SEASON
// The one rule used everywhere points are awarded (results approved by an
// approver, results approved automatically when submitted, and points
// worked out again when approved results are edited).
//
// For each registered player:
//   cutoff = the later of their most recent grade change
//            (PlayerRankingHistory, any change) and the start of the
//            current season (api/grading.php)
//   - tournament dated on or before the grade change date, or before the
//     season start: CAREER ONLY. Saved with CountsTowardSeason = 0; counts
//     in career points, never in season points (Users.TotalPoints), points
//     or finals in the current grade, or anything else for the current
//     season or grade.
//   - otherwise: as before, CountsTowardSeason = 1.
//
// Decided on the server only. Until database/points-cutoff.sql has been
// run (no CountsTowardSeason column), points are awarded as before and
// every total keeps working.
// ============================================

require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';

// True when PlayerPointsHistory.CountsTowardSeason exists (once per request)
function kbfHasSeasonFlag($pdo) {
    static $has = null;
    if ($has === null) {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'PlayerPointsHistory'
                  AND COLUMN_NAME = 'CountsTowardSeason'
            ");
            $has = (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            $has = false;
        }
        if (!$has) {
            error_log('points-rules: PlayerPointsHistory.CountsTowardSeason is missing; run database/points-cutoff.sql');
        }
    }
    return $has;
}

// SQL condition for awards that count toward the season and grade.
// $alias: the PlayerPointsHistory alias (a fixed string from the code).
function kbfSeasonAwardSql($pdo, $alias) {
    return kbfHasSeasonFlag($pdo) ? "$alias.CountsTowardSeason = 1" : '1 = 1';
}

// Start of the current season, YYYY-MM-DD
function kbfCurrentSeasonStart($pdo) {
    static $start = null;
    if ($start === null) {
        $season = kbfSeasonAt(kbfSeasonSettings($pdo)['months']);
        $start = sprintf('%04d-%02d-01', $season['startYear'], $season['startMonth']);
    }
    return $start;
}

// Each player's most recent grade change:
// [userId => ['date' => 'Y-m-d H:i:s', 'kind' => 'promotion'|'grade', 'grade' => short name]]
function kbfLastGradeChanges($pdo, array $userIds) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT rh.PlayerId, rh.ChangeDate, rh.Reason,
               oc.DisplayOrder AS OldOrder, nc.DisplayOrder AS NewOrder, nc.ShortName AS NewGrade
        FROM PlayerRankingHistory rh
        JOIN (
            SELECT PlayerId, MAX(ChangeDate) AS LastDate
            FROM PlayerRankingHistory
            WHERE PlayerId IN ($placeholders)
            GROUP BY PlayerId
        ) last ON last.PlayerId = rh.PlayerId AND last.LastDate = rh.ChangeDate
        LEFT JOIN Categories oc ON oc.CategoryId = rh.OldCategoryId
        LEFT JOIN Categories nc ON nc.CategoryId = rh.NewCategoryId
    ");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $promotion = stripos((string) $r['Reason'], 'Moved by an admin') !== 0
            && $r['OldOrder'] !== null && $r['NewOrder'] !== null
            && (int) $r['NewOrder'] < (int) $r['OldOrder'];
        $out[(int) $r['PlayerId']] = [
            'date' => (string) $r['ChangeDate'],
            'kind' => $promotion ? 'promotion' : 'grade',
            'grade' => html_entity_decode((string) $r['NewGrade'], ENT_QUOTES, 'UTF-8'),
        ];
    }
    return $out;
}

// Why an award is career only, or null when it counts toward the season.
// Returns ['kind' => 'promotion'|'grade'|'season', 'date' => Y-m-d, 'grade' => short name]
function kbfCareerOnlyReason($tournamentDate, $change, $seasonStart) {
    $t = substr((string) $tournamentDate, 0, 10);
    if ($t === '') {
        return null;
    }
    if ($change && $t <= substr($change['date'], 0, 10)) {
        return ['kind' => $change['kind'], 'date' => substr($change['date'], 0, 10), 'grade' => $change['grade']];
    }
    if ($t < $seasonStart) {
        return ['kind' => 'season', 'date' => $seasonStart, 'grade' => ''];
    }
    return null;
}

// The career-only reasons for players in one tournament: [userId => reason]
function kbfCareerOnlyFor($pdo, $tournamentId, array $userIds) {
    $stmt = $pdo->prepare("SELECT TournamentDate FROM Tournaments WHERE TournamentId = ?");
    $stmt->execute([(int) $tournamentId]);
    $date = $stmt->fetchColumn();
    if ($date === false) {
        return [];
    }
    $changes = kbfLastGradeChanges($pdo, $userIds);
    $seasonStart = kbfCurrentSeasonStart($pdo);
    $out = [];
    foreach ($userIds as $id) {
        $reason = kbfCareerOnlyReason($date, $changes[(int) $id] ?? null, $seasonStart);
        if ($reason !== null) {
            $out[(int) $id] = $reason;
        }
    }
    return $out;
}

// The note shown to admins, e.g. "Priya Shah was promoted on 3 May 2026.
// These 50 points go to career points only."
function kbfCareerOnlyNote($name, array $reason, $points) {
    $when = date('j F Y', strtotime($reason['date']));
    $tail = 'These ' . (int) $points . ' points go to career points only.';
    if ($reason['kind'] === 'promotion') {
        return $name . ' was promoted on ' . $when . '. ' . $tail;
    }
    if ($reason['kind'] === 'grade') {
        return $name . ' moved to grade ' . ($reason['grade'] !== '' ? $reason['grade'] : 'another grade') . ' on ' . $when . '. ' . $tail;
    }
    return $name . ': This tournament was in last season. ' . $tail;
}

// Award one player's points from one match. Writes PlayerPointsHistory and,
// when the award counts toward the season, adds it to Users.TotalPoints.
// Career points are worked out from PlayerPointsHistory by
// calculatePlayerStats(). Returns true when it counts toward the season.
function kbfAwardPoints($pdo, $userId, $tournamentId, $matchId, $points, $reason) {
    static $tournamentDates = [];
    static $changes = [];
    $userId = (int) $userId;
    $tournamentId = (int) $tournamentId;

    $counts = true;
    if (kbfHasSeasonFlag($pdo)) {
        if (!array_key_exists($tournamentId, $tournamentDates)) {
            $stmt = $pdo->prepare("SELECT TournamentDate FROM Tournaments WHERE TournamentId = ?");
            $stmt->execute([$tournamentId]);
            $tournamentDates[$tournamentId] = $stmt->fetchColumn() ?: null;
        }
        if (!array_key_exists($userId, $changes)) {
            $found = kbfLastGradeChanges($pdo, [$userId]);
            $changes[$userId] = $found[$userId] ?? null;
        }
        $counts = kbfCareerOnlyReason($tournamentDates[$tournamentId], $changes[$userId], kbfCurrentSeasonStart($pdo)) === null;

        $stmt = $pdo->prepare("
            INSERT INTO PlayerPointsHistory (PlayerId, TournamentId, MatchId, PointsAwarded, Reason, CountsTowardSeason)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $tournamentId, $matchId, $points, $reason, $counts ? 1 : 0]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO PlayerPointsHistory (PlayerId, TournamentId, MatchId, PointsAwarded, Reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $tournamentId, $matchId, $points, $reason]);
    }

    if ($counts) {
        $stmt = $pdo->prepare("UPDATE Users SET TotalPoints = TotalPoints + ? WHERE UserId = ?");
        $stmt->execute([$points, $userId]);
    }
    return $counts;
}

// SQL selecting, per player, the points to take off season points when
// awards are reversed (career-only awards never added to them).
// $alias: the PlayerPointsHistory alias.
function kbfSeasonPointsSumSql($pdo, $alias) {
    return "SUM(CASE WHEN " . kbfSeasonAwardSql($pdo, $alias) . " THEN $alias.PointsAwarded ELSE 0 END)";
}
?>
