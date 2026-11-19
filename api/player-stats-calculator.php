<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/security-init.php';
/**
 * Player Statistics Calculator
 *
 * kbfRecalculatePlayerStats($pdo, $userIds) works out every tracker figure
 * for a list of players (or, with null, every active player with a grade)
 * with a few set-based queries for the whole list, instead of about 13
 * queries per player. The rules are unchanged:
 *   - finals, semi-finals and quarter-finals reached: matches in tournaments
 *     dated in the last six months
 *   - start of the current grade: the latest grade change, otherwise the
 *     date the account was created
 *   - finals in the current grade: finals in tournaments on or after that
 *     start, leaving out finals whose points went to career points only
 *   - six-month points (by award date), career points (every award), points
 *     in the current grade (awards on or after the start that count toward
 *     the season)
 *   - tournaments played and the last tournament date
 *   - eligibility: the one shared rule (api/grading.php)
 *   - "eligible for promotion" is announced only when it turns 0 -> 1
 *
 * Call it AFTER the transaction that changed the data has committed, with
 * only the players affected. calculatePlayerStats($pdo, $id) is kept for
 * one player.
 */

// One player. Returns true when the player's stats were saved.
function calculatePlayerStats($pdo, $playerId) {
    $done = kbfRecalculatePlayerStats($pdo, [(int) $playerId]);
    return $done !== false && $done > 0;
}

// Returns the number of players saved, or false on a database error.
function kbfRecalculatePlayerStats($pdo, $userIds = null) {
    require_once __DIR__ . '/grading.php';
    require_once __DIR__ . '/points-rules.php';

    if ($userIds !== null) {
        $userIds = array_values(array_unique(array_filter(array_map('intval', (array) $userIds))));
        if (!$userIds) {
            return 0;
        }
        $saved = 0;
        // Large lists in chunks, so the IN lists stay small
        foreach (array_chunk($userIds, 500) as $chunk) {
            $n = kbfRecalculatePlayerStatsChunk($pdo, $chunk);
            if ($n === false) {
                return false;
            }
            $saved += $n;
        }
        return $saved;
    }
    return kbfRecalculatePlayerStatsChunk($pdo, null);
}

// The set-based calculation for one chunk of players (null = every player)
function kbfRecalculatePlayerStatsChunk($pdo, $ids) {
    try {
        $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
        $hasFlag = kbfHasSeasonFlag($pdo);
        $in = $ids === null ? '' : implode(',', array_fill(0, count($ids), '?'));
        $idParams = $ids === null ? [] : $ids;

        // 1. The players: active, in a grade, their live points and the
        //    start of their current grade
        $players = [];
        $stmt = $pdo->prepare("
            SELECT u.UserId, u.CategoryId, u.TotalPoints, u.PlayerId AS KbfId, c.Name AS CategoryName,
                   COALESCE(rh.LastChange, u.CreatedDate, '1970-01-01') AS StartDate,
                   COALESCE(pt.EligibleForPromotion, 0) AS WasEligible
            FROM Users u
            JOIN Categories c ON c.CategoryId = u.CategoryId
            LEFT JOIN (
                SELECT PlayerId, MAX(ChangeDate) AS LastChange
                FROM PlayerRankingHistory
                " . ($ids === null ? '' : "WHERE PlayerId IN ($in)") . "
                GROUP BY PlayerId
            ) rh ON rh.PlayerId = u.UserId
            LEFT JOIN PlayerPerformanceTracker pt ON pt.PlayerId = u.UserId
            WHERE u.IsActive = 1
            " . ($ids === null ? '' : "AND u.UserId IN ($in)") . "
        ");
        $stmt->execute(array_merge($idParams, $idParams));
        foreach ($stmt->fetchAll() as $r) {
            $players[(int) $r['UserId']] = $r + [
                'Finals' => 0, 'Semis' => 0, 'Quarters' => 0, 'FinalsInCat' => 0,
                'Tournaments' => 0, 'LastTournament' => null,
                'Six' => 0, 'Career' => 0, 'InCat' => 0,
            ];
        }
        if (!$players) {
            return 0;
        }
        $playerIds = array_keys($players);
        $pIn = implode(',', array_fill(0, count($playerIds), '?'));

        // 2. Matches: one row per player slot, counted per player. A match
        //    is counted once per player (DISTINCT), as the old OR query did.
        $slot = function ($col) use ($ids, $pIn) {
            return "SELECT MatchId, TournamentId, RoundType, $col AS PlayerId FROM Matches WHERE $col IS NOT NULL"
                . ($ids === null ? '' : " AND $col IN ($pIn)");
        };
        $careerOnlyJoin = $hasFlag
            ? "LEFT JOIN (SELECT DISTINCT MatchId, PlayerId FROM PlayerPointsHistory WHERE CountsTowardSeason = 0"
                . ($ids === null ? '' : " AND PlayerId IN ($pIn)") . ") co
                   ON co.MatchId = x.MatchId AND co.PlayerId = x.PlayerId"
            : "";
        $notCareerOnly = $hasFlag ? "AND co.MatchId IS NULL" : "";
        $stmt = $pdo->prepare("
            SELECT x.PlayerId,
                   COUNT(DISTINCT CASE WHEN x.RoundType = 'Final' AND t.TournamentDate >= ? THEN x.MatchId END) AS Finals,
                   COUNT(DISTINCT CASE WHEN x.RoundType = 'SemiFinal' AND t.TournamentDate >= ? THEN x.MatchId END) AS Semis,
                   COUNT(DISTINCT CASE WHEN x.RoundType = 'QuarterFinal' AND t.TournamentDate >= ? THEN x.MatchId END) AS Quarters,
                   COUNT(DISTINCT CASE WHEN x.RoundType = 'Final' AND t.TournamentDate >= s.StartDate $notCareerOnly THEN x.MatchId END) AS FinalsInCat,
                   COUNT(DISTINCT t.TournamentId) AS Tournaments,
                   MAX(t.TournamentDate) AS LastTournament
            FROM (
                " . $slot('Player1Id') . "
                UNION ALL " . $slot('Partner1Id') . "
                UNION ALL " . $slot('Player2Id') . "
                UNION ALL " . $slot('Partner2Id') . "
            ) x
            JOIN Tournaments t ON t.TournamentId = x.TournamentId
            JOIN (
                SELECT u.UserId, COALESCE(rh.LastChange, u.CreatedDate, '1970-01-01') AS StartDate
                FROM Users u
                LEFT JOIN (SELECT PlayerId, MAX(ChangeDate) AS LastChange FROM PlayerRankingHistory
                           WHERE PlayerId IN ($pIn) GROUP BY PlayerId) rh ON rh.PlayerId = u.UserId
                WHERE u.UserId IN ($pIn)
            ) s ON s.UserId = x.PlayerId
            $careerOnlyJoin
            GROUP BY x.PlayerId
        ");
        $slotParams = $ids === null ? [] : $playerIds;
        $params = array_merge(
            [$sixMonthsAgo, $sixMonthsAgo, $sixMonthsAgo],
            $slotParams, $slotParams, $slotParams, $slotParams,
            $playerIds, $playerIds,
            ($hasFlag && $ids !== null) ? $playerIds : []
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $id = (int) $r['PlayerId'];
            if (isset($players[$id])) {
                $players[$id]['Finals'] = (int) $r['Finals'];
                $players[$id]['Semis'] = (int) $r['Semis'];
                $players[$id]['Quarters'] = (int) $r['Quarters'];
                $players[$id]['FinalsInCat'] = (int) $r['FinalsInCat'];
                $players[$id]['Tournaments'] = (int) $r['Tournaments'];
                $players[$id]['LastTournament'] = $r['LastTournament'];
            }
        }

        // 3. Points: six months, career and the current grade in one pass
        $stmt = $pdo->prepare("
            SELECT pph.PlayerId,
                   SUM(CASE WHEN pph.AwardedDate >= ? THEN pph.PointsAwarded ELSE 0 END) AS Six,
                   SUM(pph.PointsAwarded) AS Career,
                   SUM(CASE WHEN pph.AwardedDate >= s.StartDate AND " . kbfSeasonAwardSql($pdo, 'pph') . "
                            THEN pph.PointsAwarded ELSE 0 END) AS InCat
            FROM PlayerPointsHistory pph
            JOIN (
                SELECT u.UserId, COALESCE(rh.LastChange, u.CreatedDate, '1970-01-01') AS StartDate
                FROM Users u
                LEFT JOIN (SELECT PlayerId, MAX(ChangeDate) AS LastChange FROM PlayerRankingHistory
                           WHERE PlayerId IN ($pIn) GROUP BY PlayerId) rh ON rh.PlayerId = u.UserId
                WHERE u.UserId IN ($pIn)
            ) s ON s.UserId = pph.PlayerId
            GROUP BY pph.PlayerId
        ");
        $stmt->execute(array_merge([$sixMonthsAgo], $playerIds, $playerIds));
        foreach ($stmt->fetchAll() as $r) {
            $id = (int) $r['PlayerId'];
            if (isset($players[$id])) {
                $players[$id]['Six'] = (int) $r['Six'];
                $players[$id]['Career'] = (int) $r['Career'];
                $players[$id]['InCat'] = (int) $r['InCat'];
            }
        }

        // 4. Eligibility (the shared rule) and one multi-row save per 200 players
        $grades = kbfEligibilityGradeMap($pdo);
        $newlyEligible = [];
        $rows = [];
        foreach ($players as $id => $p) {
            $eligible = kbfIsEligibleForPromotion($p['TotalPoints'], $p['CategoryId'], $grades) ? 1 : 0;
            if ($eligible === 1 && (int) $p['WasEligible'] !== 1) {
                $newlyEligible[] = $id;
            }
            // GroupStageFailures is no longer calculated (not entered, shown
            // or used) and FlaggedForDemotion is retired: both stored as 0
            $rows[] = [
                $id, (int) $p['CategoryId'], $p['Finals'], $p['Semis'], $p['Quarters'], 0,
                $p['Tournaments'], $p['LastTournament'], $p['Six'], $p['Career'], $p['InCat'],
                $p['FinalsInCat'], $eligible, 0,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'));
            $stmt = $pdo->prepare("
                INSERT INTO PlayerPerformanceTracker
                (PlayerId, CategoryId, FinalsReached, SemiFinalsReached, QuarterFinalsReached,
                 GroupStageFailures, TotalTournamentsPlayed, LastTournamentDate, Last6MonthsPoints,
                 CareerPoints, PointsInCurrentCategory, FinalsInCurrentCategory, EligibleForPromotion,
                 FlaggedForDemotion, LastCalculatedDate)
                VALUES $values
                ON DUPLICATE KEY UPDATE
                CategoryId = VALUES(CategoryId),
                FinalsReached = VALUES(FinalsReached),
                SemiFinalsReached = VALUES(SemiFinalsReached),
                QuarterFinalsReached = VALUES(QuarterFinalsReached),
                GroupStageFailures = VALUES(GroupStageFailures),
                TotalTournamentsPlayed = VALUES(TotalTournamentsPlayed),
                LastTournamentDate = VALUES(LastTournamentDate),
                Last6MonthsPoints = VALUES(Last6MonthsPoints),
                CareerPoints = VALUES(CareerPoints),
                PointsInCurrentCategory = VALUES(PointsInCurrentCategory),
                FinalsInCurrentCategory = VALUES(FinalsInCurrentCategory),
                EligibleForPromotion = VALUES(EligibleForPromotion),
                FlaggedForDemotion = VALUES(FlaggedForDemotion),
                LastCalculatedDate = NOW()
            ");
            $stmt->execute(array_merge(...$chunk));
        }

        // 5. Newly eligible: notify (held until after the commit when called
        //    inside a transaction; a problem never affects the calculation)
        if ($newlyEligible) {
            try {
                require_once __DIR__ . '/notify.php';
                foreach ($newlyEligible as $id) {
                    $p = $players[$id];
                    notify('eligible_for_promotion', [
                        'person' => (int) $id,
                        'values' => [
                            'kbf_id' => (string) $p['KbfId'],
                            'grade' => (string) $p['CategoryName'],
                            'points' => (string) (int) $p['TotalPoints'],
                        ],
                        'relatedType' => 'user',
                        'relatedId' => (int) $id,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Eligible-for-promotion notification error: ' . $e->getMessage());
            }
        }

        return count($players);

    } catch (PDOException $e) {
        error_log('Error calculating player stats: ' . $e->getMessage());
        return false;
    }
}
?>