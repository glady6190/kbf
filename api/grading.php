<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
// ============================================
// KBF-UK: SHARED GRADING AND SEASON LOGIC
// Used by jobs/term-reset.php, api/control-grading.php and
// api/public-settings.php, so the reset job, the preview and the public
// pages always agree.
//
//   kbfActiveGradeCondition()  "IsActive = 1" when the column exists
//   kbfActiveGrades()          active grades in rank order
//   kbfSeasonSettings()        start months, floor trigger and value, last reset
//   kbfSeasonAt()              the season a moment falls in, and the next reset
//   kbfSeasonPlan()            for given settings: current season, next reset
//                              date and what every player's points would become
//   kbfPlacingPoints()         Winners, Runners-up, Semi-finalists,
//                              Quarter-finalists from RoundPoints
//   kbfSavePlacingPoints()     writes those four into the six RoundPoints rows
//   kbfEligibilityCounts()     players eligible for promotion under given
//                              grade order and thresholds
//
// Season keys look like "2026-H2": the year the season starts and its
// position among the start months (H1, H2, ...).
// ============================================

require_once __DIR__ . '/config-helpers.php';

const KBF_MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'];
const KBF_DEFAULT_THRESHOLD = 300;

// ============================================
// GRADES
// ============================================

// Does Categories have IsActive yet? (database/grading-settings.sql)
function kbfCategoriesHaveActiveFlag($pdo) {
    static $known = null;
    if ($known !== null) {
        return $known;
    }
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Categories' AND COLUMN_NAME = 'IsActive'
        ");
        $known = (int) $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        $known = false;
    }
    return $known;
}

// SQL condition for active grades; $alias like 'c.' (fixed strings only)
function kbfActiveGradeCondition($pdo, $alias = '') {
    return kbfCategoriesHaveActiveFlag($pdo) ? $alias . 'IsActive = 1' : '1 = 1';
}

// Active grades, best rank first
function kbfActiveGrades($pdo) {
    $stmt = $pdo->query("
        SELECT CategoryId, Name, ShortName, DisplayOrder, PromotionThreshold
        FROM Categories
        WHERE " . kbfActiveGradeCondition($pdo) . "
        ORDER BY DisplayOrder ASC, CategoryId ASC
    ");
    return $stmt->fetchAll();
}

// Grade for players added by an admin: Site settings default_grade_id when it
// is an active grade, otherwise the lowest-ranked active grade
function kbfDefaultGradeId($pdo) {
    $id = kbfSettingInt('default_grade_id', 0, 1);
    if ($id > 0) {
        foreach (kbfActiveGrades($pdo) as $g) {
            if ((int) $g['CategoryId'] === $id) {
                return $id;
            }
        }
    }
    $grades = kbfActiveGrades($pdo);
    return $grades ? (int) $grades[count($grades) - 1]['CategoryId'] : null;
}

// Youngest year of birth allowed (Site settings min_player_age, 10 by default)
function kbfLatestBirthYear() {
    return (int) date('Y') - kbfSettingInt('min_player_age', 10, 5, 21);
}

// Eligible players under a grade order and thresholds.
// $grades: [CategoryId => ['order' => rank position (0 = top), 'threshold' => n]]
// Returns ['total' => n, 'byGrade' => [CategoryId => n]]
function kbfEligibilityCounts($pdo, array $grades) {
    $result = ['total' => 0, 'byGrade' => []];
    if (!$grades) {
        return $result;
    }
    $stmt = $pdo->query("
        SELECT CategoryId, TotalPoints FROM Users
        WHERE IsActive = 1 AND IsRemoved = 0 AND CategoryId IS NOT NULL
    ");
    while ($row = $stmt->fetch()) {
        $id = (int) $row['CategoryId'];
        if (kbfIsEligibleForPromotion($row['TotalPoints'], $id, $grades)) {
            $result['total']++;
            $result['byGrade'][$id] = ($result['byGrade'][$id] ?? 0) + 1;
        }
    }
    return $result;
}

// ============================================
// ELIGIBILITY FOR PROMOTION: the one rule
// Used by calculatePlayerStats() (the stored flag), the Grading page counts,
// the season reset preview and the season reset itself.
// $grades: the active grades, [CategoryId => ['order' => n, 'threshold' => n]]
// (kbfEligibilityGradeMap()). Eligible: in an active grade that is not the
// top one, with season points at or above its threshold (a threshold below
// 1 means KBF_DEFAULT_THRESHOLD).
// ============================================
function kbfIsEligibleForPromotion($points, $categoryId, array $grades) {
    $categoryId = (int) $categoryId;
    if (!isset($grades[$categoryId])) {
        return false;
    }
    $topOrder = PHP_INT_MAX;
    foreach ($grades as $g) {
        $topOrder = min($topOrder, (int) $g['order']);
    }
    if ((int) $grades[$categoryId]['order'] <= $topOrder) {
        return false;
    }
    $threshold = (int) $grades[$categoryId]['threshold'];
    if ($threshold < 1) {
        $threshold = KBF_DEFAULT_THRESHOLD;
    }
    return (int) $points >= $threshold;
}

// The active grades as they are now, in the shape kbfIsEligibleForPromotion() takes
function kbfEligibilityGradeMap($pdo) {
    $map = [];
    $stmt = $pdo->query("SELECT CategoryId, DisplayOrder, PromotionThreshold FROM Categories WHERE " . kbfActiveGradeCondition($pdo));
    foreach ($stmt->fetchAll() as $r) {
        $map[(int) $r['CategoryId']] = ['order' => (int) $r['DisplayOrder'], 'threshold' => (int) $r['PromotionThreshold']];
    }
    return $map;
}

// Recalculate stats and eligibility now (after changes to thresholds, grade
// order, points per placing or a move between grades). $userIds null: every
// active player with a grade. Call outside a transaction.
function kbfRecalculateEligibility($pdo, $userIds = null) {
    require_once __DIR__ . '/player-stats-calculator.php';
    // Set-based: a few queries for the whole list (null = every active
    // player with a grade)
    kbfRecalculatePlayerStats($pdo, $userIds === null ? null : array_map('intval', $userIds));
    if (function_exists('kbfNotifyFlush')) {
        kbfNotifyFlush();
    }
}

// ============================================
// SEASONS
// ============================================

// "1,7" -> [1, 7]; at least one valid month, otherwise [1, 7]
function kbfParseStartMonths($raw) {
    $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
    $months = [];
    foreach ($parts as $p) {
        $m = (int) trim((string) $p);
        if ($m >= 1 && $m <= 12) {
            $months[$m] = true;
        }
    }
    $months = array_keys($months);
    sort($months);
    return $months ?: [1, 7];
}

function kbfSeasonSettings($pdo) {
    return [
        'months' => kbfParseStartMonths(getSetting($pdo, 'term_start_months', '1,7')),
        'trigger' => (int) getSetting($pdo, 'term_reset_floor_trigger', 250),
        'value' => (int) getSetting($pdo, 'term_reset_floor_value', 150),
        'lastTermReset' => (string) getSetting($pdo, 'last_term_reset', ''),
    ];
}

// The season a moment falls in.
// Returns key, startMonth, startYear, nextMonth, nextYear, nextResetDate
// (Y-m-d, the 1st of the next start month) and a readable name.
function kbfSeasonAt(array $months, $timestamp = null) {
    $months = kbfParseStartMonths($months);
    $ts = $timestamp ?? time();
    $month = (int) date('n', $ts);
    $year = (int) date('Y', $ts);

    $index = null;
    foreach ($months as $i => $m) {
        if ($m <= $month) {
            $index = $i;
        }
    }
    if ($index === null) {
        // Before the first start month: still in last year's final season
        $index = count($months) - 1;
        $startYear = $year - 1;
    } else {
        $startYear = $year;
    }
    $startMonth = $months[$index];

    if ($index + 1 < count($months)) {
        $nextMonth = $months[$index + 1];
        $nextYear = $startYear;
    } else {
        $nextMonth = $months[0];
        $nextYear = $startYear + 1;
    }

    $endMonth = $nextMonth === 1 ? 12 : $nextMonth - 1;
    $endYear = $nextMonth === 1 ? $nextYear - 1 : $nextYear;
    $name = ($startYear === $endYear)
        ? KBF_MONTH_NAMES[$startMonth - 1] . ' to ' . KBF_MONTH_NAMES[$endMonth - 1] . ' ' . $startYear
        : KBF_MONTH_NAMES[$startMonth - 1] . ' ' . $startYear . ' to ' . KBF_MONTH_NAMES[$endMonth - 1] . ' ' . $endYear;

    return [
        'key' => $startYear . '-H' . ($index + 1),
        'startMonth' => $startMonth,
        'startYear' => $startYear,
        'nextMonth' => $nextMonth,
        'nextYear' => $nextYear,
        'nextResetDate' => sprintf('%04d-%02d-01', $nextYear, $nextMonth),
        'name' => $name,
    ];
}

// "1 January 2027"
function kbfDateLabel($ymd) {
    $ts = strtotime($ymd);
    return $ts === false ? (string) $ymd : date('j F Y', $ts);
}

// What the next reset does under $settings (months, trigger, value,
// lastTermReset). status:
//   baseline  no reset recorded yet: the job's first run records the current
//             season and resets nobody; the first reset is at the next start
//   due       a new season has started and the job has not reset it yet:
//             the next run of the job resets (today)
//   done      this season has been reset; the next reset is the next start
// players: every active player with a grade and what their points become
// (outcome floor, zero or skipped as eligible for promotion).
// $withPlayers:
//   true      every player (counts and total from that list)
//   false     no players, counts all 0
//   'counts'  counts and total only, from one GROUP BY query (no player list)
//   ['offset' => n, 'limit' => n]  counts and total from the GROUP BY query,
//             and only that page of players (same order as the full list)
function kbfSeasonPlan($pdo, array $settings, $timestamp = null, $withPlayers = true) {
    $season = kbfSeasonAt($settings['months'], $timestamp);
    $last = (string) ($settings['lastTermReset'] ?? '');

    if ($last === '') {
        $status = 'baseline';
        $nextReset = $season['nextResetDate'];
    } elseif ($last !== $season['key']) {
        $status = 'due';
        $nextReset = date('Y-m-d', $timestamp ?? time());
    } else {
        $status = 'done';
        $nextReset = $season['nextResetDate'];
    }

    $counts = ['floor' => 0, 'zero' => 0, 'skipped' => 0];
    $players = [];
    $total = 0;

    if ($withPlayers) {
        // The season the reset starts: the current one when it is due,
        // otherwise the next one
        $resetStart = $status === 'due'
            ? sprintf('%04d-%02d-01', $season['startYear'], $season['startMonth'])
            : $season['nextResetDate'];
        if ($withPlayers === true) {
            $players = kbfSeasonResetRows($pdo, $settings, $resetStart, false);
            foreach ($players as $p) {
                $counts[$p['outcome']]++;
            }
            $total = count($players);
        } else {
            $counts = kbfSeasonResetCounts($pdo, $settings, $resetStart);
            $total = $counts['floor'] + $counts['zero'] + $counts['skipped'];
            if (is_array($withPlayers)) {
                $players = kbfSeasonResetRows($pdo, $settings, $resetStart, false,
                    (int) ($withPlayers['offset'] ?? 0), (int) ($withPlayers['limit'] ?? 50));
            }
        }
    }

    return [
        'season' => $season,
        'status' => $status,
        'nextResetDate' => $nextReset,
        'nextResetLabel' => kbfDateLabel($nextReset),
        'counts' => $counts,
        'total' => $total,
        'players' => $players,
    ];
}

// Counted awards from tournaments dated on or after $resetStart (Y-m-d), per
// player: [PlayerId => points]. $userIds limits it to those players.
function kbfSeasonNewPoints($pdo, $resetStart, ?array $userIds = null) {
    require_once __DIR__ . '/points-rules.php';
    $newSeason = [];
    if (!kbfHasSeasonFlag($pdo)) {
        return $newSeason;
    }
    if ($userIds !== null && !$userIds) {
        return $newSeason;
    }
    $params = [$resetStart];
    $only = '';
    if ($userIds !== null) {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $only = ' AND pph.PlayerId IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
        $params = array_merge($params, $userIds);
    }
    $stmt = $pdo->prepare("
        SELECT pph.PlayerId, SUM(pph.PointsAwarded) AS Points
        FROM PlayerPointsHistory pph
        JOIN Tournaments t ON t.TournamentId = pph.TournamentId
        WHERE pph.CountsTowardSeason = 1 AND t.TournamentDate >= ?" . $only . "
        GROUP BY pph.PlayerId
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $newSeason[(int) $r['PlayerId']] = (int) $r['Points'];
    }
    return $newSeason;
}

// Counts only (floor, zero, skipped) of what the reset would do, without
// building the player list: one GROUP BY over (grade, points, new-season
// points), with the same rule as kbfSeasonResetRows() applied per group.
function kbfSeasonResetCounts($pdo, array $settings, $resetStart) {
    require_once __DIR__ . '/points-rules.php';
    $trigger = (int) $settings['trigger'];
    $grades = kbfEligibilityGradeMap($pdo);
    $counts = ['floor' => 0, 'zero' => 0, 'skipped' => 0];

    if (kbfHasSeasonFlag($pdo)) {
        $stmt = $pdo->prepare("
            SELECT u.CategoryId, GREATEST(0, u.TotalPoints) AS Cur, COALESCE(ns.Points, 0) AS NewPts, COUNT(*) AS N
            FROM Users u
            LEFT JOIN (
                SELECT pph.PlayerId, SUM(pph.PointsAwarded) AS Points
                FROM PlayerPointsHistory pph
                JOIN Tournaments t ON t.TournamentId = pph.TournamentId
                WHERE pph.CountsTowardSeason = 1 AND t.TournamentDate >= ?
                GROUP BY pph.PlayerId
            ) ns ON ns.PlayerId = u.UserId
            WHERE u.IsActive = 1 AND u.IsRemoved = 0 AND u.CategoryId IS NOT NULL
            GROUP BY u.CategoryId, Cur, NewPts
        ");
        $stmt->execute([$resetStart]);
    } else {
        $stmt = $pdo->query("
            SELECT u.CategoryId, GREATEST(0, u.TotalPoints) AS Cur, 0 AS NewPts, COUNT(*) AS N
            FROM Users u
            WHERE u.IsActive = 1 AND u.IsRemoved = 0 AND u.CategoryId IS NOT NULL
            GROUP BY u.CategoryId, Cur
        ");
    }
    while ($row = $stmt->fetch()) {
        $current = max(0, (int) $row['Cur']);
        $new = min($current, (int) $row['NewPts']);
        $old = $current - $new;
        if (kbfIsEligibleForPromotion($old, $row['CategoryId'], $grades)) {
            $outcome = 'skipped';
        } elseif ($old >= $trigger) {
            $outcome = 'floor';
        } else {
            $outcome = 'zero';
        }
        $counts[$outcome] += (int) $row['N'];
    }
    return $counts;
}

// What the season reset does to each active player with a grade, for a
// season starting on $resetStart (Y-m-d). Used by the preview (kbfSeasonPlan)
// and by the reset itself (kbfRunSeasonReset, with $forUpdate inside its
// transaction), so both decide in exactly the same way:
//   newSeason  counted awards from tournaments dated on or after $resetStart
//              (already added before the reset ran): they stay
//   old        TotalPoints without those
//   skipped    eligible for promotion on old points (the shared rule):
//              points and marks unchanged
//   floor      old >= trigger: floor value + newSeason
//   zero       otherwise: newSeason
// $offset/$limit (preview only, never with $forUpdate): just that page of
// players, in the same order.
function kbfSeasonResetRows($pdo, array $settings, $resetStart, $forUpdate, $offset = null, $limit = null) {
    require_once __DIR__ . '/points-rules.php';
    $trigger = (int) $settings['trigger'];
    $value = (int) $settings['value'];
    $grades = kbfEligibilityGradeMap($pdo);
    $paged = !$forUpdate && $limit !== null;

    $sql = "
        SELECT u.UserId, u.FirstName, u.LastName, u.PlayerId, u.TotalPoints, u.CategoryId,
               c.ShortName, c.DisplayOrder
        FROM Users u
        LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
        WHERE u.IsActive = 1
          AND u.IsRemoved = 0
          AND u.CategoryId IS NOT NULL
        ORDER BY c.DisplayOrder ASC, u.TotalPoints DESC, u.LastName ASC, u.FirstName ASC, u.UserId ASC
    ";
    if ($paged) {
        $stmt = $pdo->prepare($sql . " LIMIT ? OFFSET ?");
        $stmt->bindValue(1, max(1, min(500, (int) $limit)), PDO::PARAM_INT);
        $stmt->bindValue(2, max(0, (int) $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $newSeason = kbfSeasonNewPoints($pdo, $resetStart, array_column($rows, 'UserId'));
        $stmt = new ArrayIterator($rows);
    } else {
        $newSeason = kbfSeasonNewPoints($pdo, $resetStart);
        $stmt = $pdo->query($sql . ($forUpdate ? " FOR UPDATE" : ""));
    }
    $players = [];
    foreach ($stmt as $row) {
        $userId = (int) $row['UserId'];
        $current = max(0, (int) $row['TotalPoints']);
        $new = min($current, $newSeason[$userId] ?? 0);
        $old = $current - $new;
        if (kbfIsEligibleForPromotion($old, $row['CategoryId'], $grades)) {
            $outcome = 'skipped';
            $after = $current;
        } elseif ($old >= $trigger) {
            $outcome = 'floor';
            $after = $value + $new;
        } else {
            $outcome = 'zero';
            $after = $new;
        }
        $players[] = [
            'userId' => $userId,
            'name' => trim(html_entity_decode($row['FirstName'] . ' ' . $row['LastName'], ENT_QUOTES, 'UTF-8')),
            'kbfId' => (string) $row['PlayerId'],
            'grade' => (string) $row['ShortName'],
            'points' => $current,
            'newSeasonPoints' => $new,
            'after' => $after,
            'outcome' => $outcome,
        ];
    }
    return $players;
}

// The season reset, all in one transaction: lock last_term_reset and every
// affected player, re-read their points, apply the floor rule, keep awards
// from the new season (added back, still marked 1), mark every other counted
// award of reset players 0, and save last_term_reset. A second run for the
// same season changes nothing.
// Returns ['status' => 'baseline'|'done'|'reset', 'key', 'counts', 'floorValue']
function kbfRunSeasonReset($pdo, $timestamp = null) {
    require_once __DIR__ . '/points-rules.php';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT SettingValue FROM Settings WHERE SettingKey = 'last_term_reset' FOR UPDATE");
        $stmt->execute();
        $locked = $stmt->fetchColumn();

        $settings = kbfSeasonSettings($pdo);
        $settings['lastTermReset'] = $locked === false ? '' : (string) $locked;
        $plan = kbfSeasonPlan($pdo, $settings, $timestamp, false);
        $key = $plan['season']['key'];
        $counts = ['floor' => 0, 'zero' => 0, 'skipped' => 0];

        $saveKey = $pdo->prepare("
            INSERT INTO Settings (SettingKey, SettingValue) VALUES ('last_term_reset', ?)
            ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
        ");

        if ($plan['status'] === 'done') {
            $pdo->rollBack();
            return ['status' => 'done', 'key' => $key, 'counts' => $counts, 'floorValue' => (int) $settings['value']];
        }
        if ($plan['status'] === 'baseline') {
            $saveKey->execute([$key]);
            $pdo->commit();
            return ['status' => 'baseline', 'key' => $key, 'counts' => $counts, 'floorValue' => (int) $settings['value']];
        }

        $resetStart = sprintf('%04d-%02d-01', $plan['season']['startYear'], $plan['season']['startMonth']);
        $rows = kbfSeasonResetRows($pdo, $settings, $resetStart, true);

        $setPoints = $pdo->prepare("UPDATE Users SET TotalPoints = ? WHERE UserId = ?");
        $endOld = kbfHasSeasonFlag($pdo) ? $pdo->prepare("
            UPDATE PlayerPointsHistory pph
            LEFT JOIN Tournaments t ON t.TournamentId = pph.TournamentId
            SET pph.CountsTowardSeason = 0
            WHERE pph.PlayerId = ? AND pph.CountsTowardSeason = 1
              AND (t.TournamentDate IS NULL OR t.TournamentDate < ?)
        ") : null;
        foreach ($rows as $p) {
            $counts[$p['outcome']]++;
            if ($p['outcome'] === 'skipped') {
                continue;
            }
            $setPoints->execute([$p['after'], $p['userId']]);
            if ($endOld) {
                $endOld->execute([$p['userId'], $resetStart]);
            }
        }

        $saveKey->execute([$key]);
        $pdo->commit();
        return ['status' => 'reset', 'key' => $key, 'counts' => $counts, 'floorValue' => (int) $settings['value']];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ============================================
// POINTS PER PLACING
// ============================================
const KBF_PLACING_ROWS = [
    // placing => [[RoundType, Outcome, Reason], ...]
    'winners' => [['Final', 'Winner', 'Winner']],
    'runnersUp' => [['Final', 'Loser', 'Runner-up']],
    'semiFinalists' => [['SemiFinal', 'Winner', 'Semi-Finalist'], ['SemiFinal', 'Loser', 'Semi-Finalist']],
    'quarterFinalists' => [['QuarterFinal', 'Winner', 'Quarter-Finalist'], ['QuarterFinal', 'Loser', 'Quarter-Finalist']],
];
const KBF_PLACING_DEFAULTS = ['winners' => 100, 'runnersUp' => 75, 'semiFinalists' => 50, 'quarterFinalists' => 30];

// Read fresh from RoundPoints (not the per-request cache), with the
// historical values when a row is missing
function kbfPlacingPoints($pdo) {
    $values = KBF_PLACING_DEFAULTS;
    try {
        $rows = $pdo->query("SELECT RoundType, Outcome, Points FROM RoundPoints")->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['RoundType'] . '|' . $r['Outcome']] = (int) $r['Points'];
        }
        foreach (KBF_PLACING_ROWS as $placing => $targets) {
            $first = $targets[0];
            if (isset($map[$first[0] . '|' . $first[1]])) {
                $values[$placing] = $map[$first[0] . '|' . $first[1]];
            }
        }
    } catch (PDOException $e) {
        error_log('kbfPlacingPoints: ' . $e->getMessage());
    }
    return $values;
}

// Save the four values into the six rows, so the pairs never differ.
// Call inside the caller's transaction.
function kbfSavePlacingPoints($pdo, array $values) {
    $exists = $pdo->prepare("SELECT COUNT(*) FROM RoundPoints WHERE RoundType = ? AND Outcome = ?");
    $update = $pdo->prepare("UPDATE RoundPoints SET Points = ? WHERE RoundType = ? AND Outcome = ?");
    $insert = $pdo->prepare("INSERT INTO RoundPoints (RoundType, Outcome, Points, Reason) VALUES (?, ?, ?, ?)");
    foreach (KBF_PLACING_ROWS as $placing => $targets) {
        if (!array_key_exists($placing, $values)) {
            continue;
        }
        $points = (int) $values[$placing];
        foreach ($targets as $t) {
            $exists->execute([$t[0], $t[1]]);
            if ((int) $exists->fetchColumn() > 0) {
                $update->execute([$points, $t[0], $t[1]]);
            } else {
                $insert->execute([$t[0], $t[1], $points, $t[2]]);
            }
        }
    }
}
?>
