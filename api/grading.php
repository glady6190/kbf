<?php
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
    $topId = null;
    $topOrder = PHP_INT_MAX;
    foreach ($grades as $id => $g) {
        if ($g['order'] < $topOrder) {
            $topOrder = $g['order'];
            $topId = $id;
        }
    }
    $stmt = $pdo->query("
        SELECT CategoryId, TotalPoints FROM Users
        WHERE IsActive = 1 AND IsRemoved = 0 AND CategoryId IS NOT NULL
    ");
    while ($row = $stmt->fetch()) {
        $id = (int) $row['CategoryId'];
        if (!isset($grades[$id]) || $id === $topId) {
            continue;
        }
        $threshold = (int) $grades[$id]['threshold'];
        if ($threshold < 1) {
            $threshold = KBF_DEFAULT_THRESHOLD;
        }
        if ((int) $row['TotalPoints'] >= $threshold) {
            $result['total']++;
            $result['byGrade'][$id] = ($result['byGrade'][$id] ?? 0) + 1;
        }
    }
    return $result;
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

    $trigger = (int) $settings['trigger'];
    $value = (int) $settings['value'];
    $counts = ['floor' => 0, 'zero' => 0, 'skipped' => 0];
    $players = [];

    if ($withPlayers) {
        $stmt = $pdo->query("
            SELECT u.UserId, u.FirstName, u.LastName, u.PlayerId, u.TotalPoints,
                   c.ShortName, c.DisplayOrder,
                   COALESCE(pt.EligibleForPromotion, 0) AS EligibleForPromotion
            FROM Users u
            LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
            LEFT JOIN PlayerPerformanceTracker pt ON u.UserId = pt.PlayerId
            WHERE u.IsActive = 1
              AND u.IsRemoved = 0
              AND u.CategoryId IS NOT NULL
            ORDER BY c.DisplayOrder ASC, u.TotalPoints DESC, u.LastName ASC, u.FirstName ASC
        ");
        while ($row = $stmt->fetch()) {
            $current = (int) $row['TotalPoints'];
            if ((int) $row['EligibleForPromotion'] === 1) {
                $outcome = 'skipped';
                $after = $current;
            } elseif ($current >= $trigger) {
                $outcome = 'floor';
                $after = $value;
            } else {
                $outcome = 'zero';
                $after = 0;
            }
            $counts[$outcome]++;
            $players[] = [
                'userId' => (int) $row['UserId'],
                'name' => trim(html_entity_decode($row['FirstName'] . ' ' . $row['LastName'], ENT_QUOTES, 'UTF-8')),
                'kbfId' => (string) $row['PlayerId'],
                'grade' => (string) $row['ShortName'],
                'points' => $current,
                'after' => $after,
                'outcome' => $outcome,
            ];
        }
    }

    return [
        'season' => $season,
        'status' => $status,
        'nextResetDate' => $nextReset,
        'nextResetLabel' => kbfDateLabel($nextReset),
        'counts' => $counts,
        'players' => $players,
    ];
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
