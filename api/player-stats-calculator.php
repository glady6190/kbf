<?php
require_once __DIR__ . '/security-init.php';
/**
 * Player Statistics Calculator
 * Reusable function to calculate a single player's stats
 * Can be called from rankings.php or the daily cron job
 */

function calculatePlayerStats($pdo, $playerId) {
    try {
        // Get player's current category, live points, and the category's
        // promotion threshold + rank (DisplayOrder)
        $stmt = $pdo->prepare("
            SELECT u.CategoryId, u.TotalPoints, c.PromotionThreshold, c.DisplayOrder
            FROM Users u JOIN Categories c ON u.CategoryId = c.CategoryId
            WHERE u.UserId = ? AND u.IsActive = 1
        ");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();

        if (!$player) {
            return false;
        }

        $categoryId = $player['CategoryId'];
        $totalPoints = $player['TotalPoints'];
        $promotionThreshold = $player['PromotionThreshold'];
        $playerDisplayOrder = $player['DisplayOrder'];

        // Top grade = the active category with the lowest DisplayOrder (rank
        // lives in DisplayOrder, never in CategoryId; retired grades don't count)
        require_once __DIR__ . '/grading.php';
        $stmt = $pdo->query("SELECT MIN(DisplayOrder) AS TopOrder FROM Categories WHERE " . kbfActiveGradeCondition($pdo));
        $topOrder = $stmt->fetch()['TopOrder'];
        
        // Calculate date 6 months ago
        $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
        
        // Count finals reached in last 6 months
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as FinalsCount
            FROM Matches m
            JOIN Tournaments t ON m.TournamentId = t.TournamentId
            WHERE (m.Player1Id = ? OR m.Partner1Id = ? OR m.Player2Id = ? OR m.Partner2Id = ?)
            AND m.RoundType = 'Final'
            AND t.TournamentDate >= ?
        ");
        $stmt->execute([$playerId, $playerId, $playerId, $playerId, $sixMonthsAgo]);
        $finalsReached = $stmt->fetch()['FinalsCount'];
        
        // Get category start date
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(ChangeDate), u.CreatedDate) as CategoryStartDate
            FROM PlayerRankingHistory prh
            RIGHT JOIN Users u ON u.UserId = ?
            WHERE prh.PlayerId = ? OR prh.PlayerId IS NULL
        ");
        $stmt->execute([$playerId, $playerId]);
        $categoryStartDate = $stmt->fetch()['CategoryStartDate'];
        
        // Count finals in current category. A final whose points went to
        // career points only (api/points-rules.php) does not count here.
        require_once __DIR__ . '/points-rules.php';
        $careerOnlyFinalSql = kbfHasSeasonFlag($pdo)
            ? "AND NOT EXISTS (SELECT 1 FROM PlayerPointsHistory cp
                               WHERE cp.MatchId = m.MatchId AND cp.PlayerId = ? AND cp.CountsTowardSeason = 0)"
            : "";
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as FinalsInCat
            FROM Matches m
            JOIN Tournaments t ON m.TournamentId = t.TournamentId
            WHERE (m.Player1Id = ? OR m.Partner1Id = ? OR m.Player2Id = ? OR m.Partner2Id = ?)
            AND m.RoundType = 'Final'
            AND t.TournamentDate >= ?
            $careerOnlyFinalSql
        ");
        $finalsParams = [$playerId, $playerId, $playerId, $playerId, $categoryStartDate];
        if ($careerOnlyFinalSql !== '') {
            $finalsParams[] = $playerId;
        }
        $stmt->execute($finalsParams);
        $finalsInCurrentCategory = $stmt->fetch()['FinalsInCat'];
        
        // Count semi-finals in last 6 months
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as SemisCount
            FROM Matches m
            JOIN Tournaments t ON m.TournamentId = t.TournamentId
            WHERE (m.Player1Id = ? OR m.Partner1Id = ? OR m.Player2Id = ? OR m.Partner2Id = ?)
            AND m.RoundType = 'SemiFinal'
            AND t.TournamentDate >= ?
        ");
        $stmt->execute([$playerId, $playerId, $playerId, $playerId, $sixMonthsAgo]);
        $semiFinalsReached = $stmt->fetch()['SemisCount'];
        
        // Count quarter-finals in last 6 months
$stmt = $pdo->prepare("
    SELECT COUNT(*) as QuartersCount
    FROM Matches m
    JOIN Tournaments t ON m.TournamentId = t.TournamentId
    WHERE (m.Player1Id = ? OR m.Partner1Id = ? OR m.Player2Id = ? OR m.Partner2Id = ?)
    AND m.RoundType = 'QuarterFinal'
    AND t.TournamentDate >= ?
");
$stmt->execute([$playerId, $playerId, $playerId, $playerId, $sixMonthsAgo]);
$quarterFinalsReached = $stmt->fetch()['QuartersCount'];
        
        // Count group stage failures (still stored on the tracker for stats display;
        // no longer drives any decision now that demotion flagging is retired)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as FailCount
            FROM Matches m
            JOIN Tournaments t ON m.TournamentId = t.TournamentId
            WHERE (m.Player1Id = ? OR m.Partner1Id = ? OR m.Player2Id = ? OR m.Partner2Id = ?)
            AND m.RoundType = 'GroupStage'
            AND ((m.Player1Id = ? OR m.Partner1Id = ?) AND m.WinnerTeam = 2)
               OR ((m.Player2Id = ? OR m.Partner2Id = ?) AND m.WinnerTeam = 1)
            AND t.TournamentDate >= ?
        ");
        $stmt->execute([
            $playerId, $playerId, $playerId, $playerId,
            $playerId, $playerId, $playerId, $playerId,
            $sixMonthsAgo
        ]);
        $groupStageFailures = $stmt->fetch()['FailCount'];
        
        // Calculate 6-month points
        $stmt = $pdo->prepare("
            SELECT SUM(PointsAwarded) as TotalPoints
            FROM PlayerPointsHistory
            WHERE PlayerId = ? AND AwardedDate >= ?
        ");
        $stmt->execute([$playerId, $sixMonthsAgo]);
        $last6MonthsPoints = $stmt->fetch()['TotalPoints'] ?: 0;
        
        // Calculate career points
        $stmt = $pdo->prepare("
            SELECT SUM(PointsAwarded) as CareerPoints
            FROM PlayerPointsHistory
            WHERE PlayerId = ?
        ");
        $stmt->execute([$playerId]);
        $careerPoints = $stmt->fetch()['CareerPoints'] ?: 0;
        
        // Calculate points in current category (career-only awards left out;
        // career points above keep them)
        $stmt = $pdo->prepare("
            SELECT SUM(pph.PointsAwarded) as CategoryPoints
            FROM PlayerPointsHistory pph
            WHERE pph.PlayerId = ? AND pph.AwardedDate >= ?
              AND " . kbfSeasonAwardSql($pdo, 'pph') . "
        ");
        $stmt->execute([$playerId, $categoryStartDate]);
        $pointsInCurrentCategory = $stmt->fetch()['CategoryPoints'] ?: 0;
        
        // Get total tournaments played
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT t.TournamentId) as TournamentCount,
                   MAX(t.TournamentDate) as LastTournament
            FROM Matches m
            JOIN Tournaments t ON m.TournamentId = t.TournamentId
            WHERE (m.Player1Id = ? OR m.Partner1Id = ? OR m.Player2Id = ? OR m.Partner2Id = ?)
        ");
        $stmt->execute([$playerId, $playerId, $playerId, $playerId]);
        $tournamentData = $stmt->fetch();
        $totalTournaments = $tournamentData['TournamentCount'];
        $lastTournament = $tournamentData['LastTournament'];
        
        // Check promotion eligibility — data-driven: live points (Users.TotalPoints)
        // vs the category's threshold, unless already in the top grade.
        $threshold = $promotionThreshold ?? 300;
        $eligibleForPromotion = 0;
        if ($totalPoints >= $threshold && $playerDisplayOrder > $topOrder) {
            $eligibleForPromotion = 1;
        }
        
        // Demotion flagging is RETIRED. We no longer compute it (the old rules
        // were GroupStageFailures >= 3 and >12 months inactivity). The
        // FlaggedForDemotion column is kept and still written below (always 0)
        // for reversibility — restore the two checks here to bring it back.
        $flaggedForDemotion = 0;

        // Eligible flag before this calculation (no tracker row counts as 0),
        // so "eligible for promotion" is announced only when it turns 0 -> 1
        $stmt = $pdo->prepare("SELECT EligibleForPromotion FROM PlayerPerformanceTracker WHERE PlayerId = ?");
        $stmt->execute([$playerId]);
        $wasEligible = (int) $stmt->fetchColumn() === 1;

       // Update PlayerPerformanceTracker
$stmt = $pdo->prepare("
    INSERT INTO PlayerPerformanceTracker 
    (PlayerId, CategoryId, FinalsReached, SemiFinalsReached, QuarterFinalsReached, 
     GroupStageFailures, TotalTournamentsPlayed, LastTournamentDate, Last6MonthsPoints, 
     CareerPoints, PointsInCurrentCategory, FinalsInCurrentCategory, EligibleForPromotion, 
     FlaggedForDemotion, LastCalculatedDate)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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

$stmt->execute([
    $playerId,
    $categoryId,
    $finalsReached,
    $semiFinalsReached,
    $quarterFinalsReached,
    $groupStageFailures,
    $totalTournaments,
    $lastTournament,
    $last6MonthsPoints,
    $careerPoints,
    $pointsInCurrentCategory,
    $finalsInCurrentCategory,
    $eligibleForPromotion,
    $flaggedForDemotion
]);

        // Newly eligible: notify (held until after the commit when called
        // inside a transaction; a problem never affects the calculation)
        if (!$wasEligible && $eligibleForPromotion === 1) {
            try {
                require_once __DIR__ . '/notify.php';
                $stmt = $pdo->prepare("
                    SELECT u.PlayerId, c.Name AS CategoryName
                    FROM Users u JOIN Categories c ON c.CategoryId = u.CategoryId
                    WHERE u.UserId = ?
                ");
                $stmt->execute([$playerId]);
                $info = $stmt->fetch();
                notify('eligible_for_promotion', [
                    'person' => (int) $playerId,
                    'values' => [
                        'kbf_id' => $info ? (string) $info['PlayerId'] : '',
                        'grade' => $info ? (string) $info['CategoryName'] : '',
                        'points' => (string) (int) $totalPoints,
                    ],
                    'relatedType' => 'user',
                    'relatedId' => (int) $playerId,
                ]);
            } catch (\Throwable $e) {
                error_log('Eligible-for-promotion notification error: ' . $e->getMessage());
            }
        }

        return true;
        
    } catch (PDOException $e) {
        error_log('Error calculating player stats: ' . $e->getMessage());
        return false;
    }
}
?>