<?php
// ============================================
// KBF-UK: PUBLIC PLAYER PROFILE (GET /api/users.php?id=)
// Used by player-profile.html.
//
// Privacy (api/config-helpers.php):
//   * no year of birth or age, for anyone
//   * an under-18 player's photo is sent only to signed-in staff
//   * grade changes show date, grades and direction only: never the
//     admin's reason, notes or name
//   * deactivated or removed players: "This player is no longer listed."
//   * removed partners read "Former member", unlinked
//
// Response:
//   player   id, kbfId, name, firstName, lastName, initials, city, photo,
//            grade {short, name, top}, seasonPoints, threshold, toGo, eligible,
//            careerPoints, finals {won, runnerUp}, tournaments
//   results  one row per tournament and category, newest first:
//            date, tournamentId, tournament, category, finished
//            (winner|runnerUp|semi|quarter), points, careerOnly, thisSeason,
//            partner {type: player|guest|former|none, id, name}
//   journey  joinedGrade, joinedDate, changes [{date, from, to, kind}]
//            kind: promoted | down | moved (oldest first)
// ============================================

require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/points-rules.php';

const KBF_PROFILE_PHOTO_PATTERN = '#^/uploads/(profiles|registrations)/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#';
const KBF_PROFILE_ROUND_RANK = ['Final' => 3, 'SemiFinal' => 2, 'QuarterFinal' => 1];

function kbfProfileText($value) {
    return $value === null ? '' : trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
}

function kbfProfileInitials($first, $last) {
    $f = function_exists('mb_substr') ? mb_substr($first, 0, 1, 'UTF-8') : substr($first, 0, 1);
    $l = function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($f . $l, 'UTF-8') : strtoupper($f . $l);
}

function kbfSendPublicProfile($pdo, $userId) {
    $userId = (int) $userId;

    // ---------- listed? ----------
    $stmt = $pdo->prepare("SELECT IsActive, COALESCE(IsRemoved, 0) AS IsRemoved FROM Users WHERE UserId = ?");
    $stmt->execute([$userId]);
    $state = $stmt->fetch();
    if (!$state) {
        sendJSON(['success' => false, 'message' => 'Player not found'], 404);
    }
    if ((int) $state['IsActive'] !== 1 || (int) $state['IsRemoved'] === 1) {
        sendJSON(['success' => false, 'notListed' => true, 'message' => 'This player is no longer listed.'], 404);
    }

    // ---------- player ----------
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.PlayerId, u.FirstName, u.LastName, u.Location, u.ProfilePhoto, u.YearOfBirth,
               u.TotalPoints, u.CategoryId, u.CreatedDate,
               c.Name AS GradeName, c.ShortName AS GradeShort, c.PromotionThreshold,
               COALESCE(pt.EligibleForPromotion, 0) AS EligibleForPromotion
        FROM Users u
        LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
        LEFT JOIN PlayerPerformanceTracker pt ON pt.PlayerId = u.UserId
        WHERE u.UserId = ?
    ");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();

    $first = kbfProfileText($u['FirstName']);
    $last = kbfProfileText($u['LastName']);
    $photo = $u['ProfilePhoto'];
    if (!is_string($photo) || !preg_match(KBF_PROFILE_PHOTO_PATTERN, $photo)) {
        $photo = null;
    }
    if ($photo !== null && kbfIsUnder18($u['YearOfBirth']) && !kbfViewerIsStaff()) {
        $photo = null;
    }

    // Grade, top grade and threshold (active grades in rank order)
    $isTop = false;
    $threshold = null;
    foreach (kbfActiveGrades($pdo) as $i => $g) {
        if ((int) $g['CategoryId'] === (int) $u['CategoryId']) {
            $isTop = $i === 0;
            if (!$isTop) {
                $threshold = (int) $g['PromotionThreshold'] > 0 ? (int) $g['PromotionThreshold'] : KBF_DEFAULT_THRESHOLD;
            }
            break;
        }
    }
    $seasonPoints = (int) $u['TotalPoints'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(PointsAwarded), 0) FROM PlayerPointsHistory WHERE PlayerId = ?");
    $stmt->execute([$userId]);
    $careerPoints = (int) $stmt->fetchColumn();

    // ---------- results ----------
    $careerOnlySql = kbfHasSeasonFlag($pdo) ? 'pph.CountsTowardSeason = 0' : '0';
    $stmt = $pdo->prepare("
        SELECT m.MatchId, m.TournamentId, m.RoundType, m.WinnerTeam, m.GroupName,
               m.Player1Id, m.Partner1Id, m.Player2Id, m.Partner2Id,
               m.GuestPlayer1Name, m.GuestPartner1Name, m.GuestPlayer2Name, m.GuestPartner2Name,
               cat.Name AS CategoryName, t.Name AS TournamentName, t.TournamentDate,
               p1.FirstName AS P1FirstName, p1.LastName AS P1LastName,
               p2.FirstName AS Partner1FirstName, p2.LastName AS Partner1LastName,
               p3.FirstName AS P2FirstName, p3.LastName AS P2LastName,
               p4.FirstName AS Partner2FirstName, p4.LastName AS Partner2LastName,
               COALESCE(p1.IsRemoved, 0) AS P1Removed, COALESCE(p2.IsRemoved, 0) AS Partner1Removed,
               COALESCE(p3.IsRemoved, 0) AS P2Removed, COALESCE(p4.IsRemoved, 0) AS Partner2Removed,
               COALESCE(pph.PointsAwarded, 0) AS PointsAwarded,
               COALESCE($careerOnlySql, 0) AS CareerOnly
        FROM Matches m
        JOIN Tournaments t ON t.TournamentId = m.TournamentId
        LEFT JOIN Categories cat ON cat.CategoryId = m.CategoryId
        LEFT JOIN Users p1 ON p1.UserId = m.Player1Id
        LEFT JOIN Users p2 ON p2.UserId = m.Partner1Id
        LEFT JOIN Users p3 ON p3.UserId = m.Player2Id
        LEFT JOIN Users p4 ON p4.UserId = m.Partner2Id
        LEFT JOIN PlayerPointsHistory pph ON pph.MatchId = m.MatchId AND pph.PlayerId = ?
        WHERE (m.Player1Id = ? OR m.Partner1Id = ? OR m.Player2Id = ? OR m.Partner2Id = ?)
          AND m.IsApproved = 1
        ORDER BY t.TournamentDate DESC, m.TournamentId DESC, m.MatchId ASC
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);

    // slot => [id field, first, last, guest, removed flag]
    $slots = [
        1 => ['Player1Id', 'P1FirstName', 'P1LastName', 'GuestPlayer1Name', 'P1Removed'],
        2 => ['Partner1Id', 'Partner1FirstName', 'Partner1LastName', 'GuestPartner1Name', 'Partner1Removed'],
        3 => ['Player2Id', 'P2FirstName', 'P2LastName', 'GuestPlayer2Name', 'P2Removed'],
        4 => ['Partner2Id', 'Partner2FirstName', 'Partner2LastName', 'GuestPartner2Name', 'Partner2Removed'],
    ];
    $partnerOf = [1 => 2, 2 => 1, 3 => 4, 4 => 3];
    $seasonStart = kbfCurrentSeasonStart($pdo);

    $groups = [];
    foreach ($stmt->fetchAll() as $m) {
        $mine = null;
        foreach ($slots as $slot => $f) {
            if ((int) $m[$f[0]] === $userId) {
                $mine = $slot;
                break;
            }
        }
        if ($mine === null) {
            continue;
        }
        $category = kbfProfileText($m['GroupName']);
        if ($category === '') {
            $category = kbfProfileText($m['CategoryName']);
        }
        $key = (int) $m['TournamentId'] . '|' . strtolower($category);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'date' => substr((string) $m['TournamentDate'], 0, 10),
                'tournamentId' => (int) $m['TournamentId'],
                'tournament' => kbfProfileText($m['TournamentName']),
                'category' => $category,
                'rank' => 0,
                'finished' => null,
                'points' => 0,
                'careerOnly' => false,
                'partner' => ['type' => 'none'],
            ];
        }
        $g = &$groups[$key];
        $points = (int) $m['PointsAwarded'];
        $g['points'] += $points;
        if ($points > 0 && (int) $m['CareerOnly'] === 1) {
            $g['careerOnly'] = true;
        }

        $rank = KBF_PROFILE_ROUND_RANK[$m['RoundType']] ?? 0;
        if ($rank > $g['rank']) {
            $g['rank'] = $rank;
            $team = $mine <= 2 ? 1 : 2;
            if ($rank === 3) {
                $g['finished'] = ((int) $m['WinnerTeam'] === $team) ? 'winner' : 'runnerUp';
            } else {
                $g['finished'] = $rank === 2 ? 'semi' : 'quarter';
            }
            // Partner in that match
            $p = $slots[$partnerOf[$mine]];
            if ((int) $m[$p[4]] === 1) {
                $g['partner'] = ['type' => 'former', 'name' => 'Former member'];
            } elseif (!empty($m[$p[0]])) {
                $g['partner'] = [
                    'type' => 'player',
                    'id' => (int) $m[$p[0]],
                    'name' => trim(kbfProfileText($m[$p[1]]) . ' ' . kbfProfileText($m[$p[2]])),
                ];
            } elseif (trim((string) $m[$p[3]]) !== '') {
                $g['partner'] = ['type' => 'guest', 'name' => kbfProfileText($m[$p[3]])];
            } else {
                $g['partner'] = ['type' => 'none'];
            }
        }
        unset($g);
    }

    $results = [];
    $won = 0;
    $runnerUp = 0;
    $tournaments = [];
    foreach ($groups as $g) {
        if ($g['finished'] === null) {
            continue;   // only placings (final, semi-final, quarter-final)
        }
        if ($g['finished'] === 'winner') {
            $won++;
        } elseif ($g['finished'] === 'runnerUp') {
            $runnerUp++;
        }
        $tournaments[$g['tournamentId']] = true;
        $results[] = [
            'date' => $g['date'],
            'tournamentId' => $g['tournamentId'],
            'tournament' => $g['tournament'],
            'category' => $g['category'],
            'finished' => $g['finished'],
            'points' => $g['points'],
            'careerOnly' => $g['careerOnly'],
            'thisSeason' => $g['date'] !== '' && $g['date'] >= $seasonStart,
            'partner' => $g['partner'],
        ];
    }

    // ---------- grade journey (no reasons, notes or admin names) ----------
    $stmt = $pdo->prepare("
        SELECT rh.ChangeDate, oc.ShortName AS OldShort, nc.ShortName AS NewShort,
               oc.DisplayOrder AS OldOrder, nc.DisplayOrder AS NewOrder
        FROM PlayerRankingHistory rh
        LEFT JOIN Categories oc ON oc.CategoryId = rh.OldCategoryId
        LEFT JOIN Categories nc ON nc.CategoryId = rh.NewCategoryId
        WHERE rh.PlayerId = ?
        ORDER BY rh.ChangeDate ASC
    ");
    $stmt->execute([$userId]);
    $changes = [];
    foreach ($stmt->fetchAll() as $r) {
        $kind = 'moved';
        if ($r['OldOrder'] !== null && $r['NewOrder'] !== null) {
            if ((int) $r['NewOrder'] < (int) $r['OldOrder']) {
                $kind = 'promoted';
            } elseif ((int) $r['NewOrder'] > (int) $r['OldOrder']) {
                $kind = 'down';
            }
        }
        $changes[] = [
            'date' => substr((string) $r['ChangeDate'], 0, 10),
            'from' => kbfProfileText($r['OldShort']),
            'to' => kbfProfileText($r['NewShort']),
            'kind' => $kind,
        ];
    }
    $gradeShort = kbfProfileText($u['GradeShort']);
    $joinedGrade = $changes ? $changes[0]['from'] : $gradeShort;

    sendJSON([
        'success' => true,
        'player' => [
            'id' => $userId,
            'kbfId' => (string) $u['PlayerId'],
            'firstName' => $first,
            'lastName' => $last,
            'name' => trim($first . ' ' . $last),
            'initials' => kbfProfileInitials($first, $last),
            'city' => kbfProfileText($u['Location']),
            'photo' => $photo,
            'grade' => [
                'short' => $gradeShort,
                'name' => kbfProfileText($u['GradeName']),
                'top' => $isTop,
            ],
            'seasonPoints' => $seasonPoints,
            'threshold' => $threshold,
            'toGo' => $threshold !== null ? max(0, $threshold - $seasonPoints) : null,
            'eligible' => !$isTop && ((int) $u['EligibleForPromotion'] === 1 || ($threshold !== null && $seasonPoints >= $threshold)),
            'careerPoints' => $careerPoints,
            'finals' => ['won' => $won, 'runnerUp' => $runnerUp],
            'tournaments' => count($tournaments),
        ],
        'results' => $results,
        'journey' => [
            'joinedGrade' => $joinedGrade,
            'joinedDate' => $u['CreatedDate'] ? substr((string) $u['CreatedDate'], 0, 10) : null,
            'changes' => $changes,
        ],
    ]);
}
?>
