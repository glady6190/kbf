<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/notify.php';

// ============================================
// MATCHES (RESULTS) API
//   GET ?tournamentId=                 approved matches (public)
//   GET ?action=pointsNotes&tournamentId=&players=1,2,3
//                                      players whose points from this
//                                      tournament would be career only
//   GET ?action=form&tournamentId=     everything the results form needs:
//                                      tournament, whether results can be
//                                      entered, categories and their results,
//                                      players to choose from, round points
//   POST {tournamentId, categoryName, matches}
//                                      submit one category (approved straight
//                                      away with tournaments.approve)
//   PUT  {tournamentId, categoryName, matches}
//                                      replace one category's results
//                                      tournaments.manage_all: any results
//                                      (approved results: points reversed and
//                                      worked out again); organisers: their
//                                      own tournament's results while waiting
//   DELETE ?tournamentId=&category=    delete one category's results while
//                                      waiting for approval
//   DELETE ?id=                        delete one match (tournaments.manage_all)
//
// Results can be entered only for an approved tournament (Approved, or
// Completed once results are in) dated today or earlier. The category name
// is for reference only; players' grades are never checked against it.
// tournaments.manage_all: any tournament; tournaments.organise_own: their
// own tournaments only.
// ============================================

// Authenticate and validate CSRF for state-changing requests.
// Auth runs BEFORE CSRF so an expired/destroyed session returns a clear 401
// "session expired" instead of a misleading 403 "Invalid security token".
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}

// ============================================
    // HELPER: resolve a player slot
    // ============================================
    function resolvePlayerSlot($slot) {
        if (is_null($slot)) {
            return ['id' => null, 'guestName' => null];
        }
        if (is_numeric($slot) && (int)$slot > 0) {
            return ['id' => (int)$slot, 'guestName' => null];
        }
        $guestName = trim((string)$slot);
        if (strlen($guestName) === 0) {
            return ['id' => null, 'guestName' => null];
        }
        if (strlen($guestName) > 100) {
            $guestName = substr($guestName, 0, 100);
        }
        $guestName = strip_tags($guestName);
        return ['id' => null, 'guestName' => $guestName];
    }

// ============================================
// RESULTS FORM HELPERS
// ============================================
const RESULTS_ROUNDS = ['QuarterFinal', 'SemiFinal', 'Final'];

// Grouping key for a category name: trimmed, spaces collapsed, case ignored
function resultsCategoryKey($name) {
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

// A category name typed or chosen on the form. Returns [name, error].
function resultsCleanCategory($value) {
    $text = is_string($value) ? strip_tags($value) : '';
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    if ($text === '') {
        return [null, 'Please choose the category these results are for'];
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > 50) {
        return [null, 'The category name must be 50 characters or fewer'];
    }
    return [$text, null];
}

function resultsLoadTournament(PDO $pdo, $tournamentId) {
    $stmt = $pdo->prepare("
        SELECT t.TournamentId, t.Name, t.TournamentDate, t.Venue, t.Location, t.Status,
               t.CreatedByAdminId, t.CategoriesOffered,
               " . organiserNameSql('t', 'c') . " AS OrganiserName
        FROM Tournaments t
        LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
        WHERE t.TournamentId = ?
    ");
    $stmt->execute([(int) $tournamentId]);
    return $stmt->fetch();
}

// Permission and ownership (stage 3a rules). Returns null or [message, code].
function resultsAccessError($tournament) {
    if (!hasAnyPermission(['tournaments.manage_all', 'tournaments.organise_own'])) {
        return ['Insufficient permissions', 403];
    }
    if (!hasPermission('tournaments.manage_all') && !isOwnTournamentRow($tournament)) {
        return ['You can only enter results for your own tournaments', 403];
    }
    return null;
}

// Why results cannot be entered yet, or null when they can
function resultsEntryBlock($tournament) {
    $status = (string) $tournament['Status'];
    if ($status === 'Rejected') {
        return 'This tournament was rejected, so results cannot be entered.';
    }
    if ($status === 'Cancelled') {
        return 'This tournament was cancelled, so results cannot be entered.';
    }
    if (!in_array($status, ['Approved', 'Completed'], true)) {
        return 'This tournament is not approved yet.';
    }
    $date = substr((string) $tournament['TournamentDate'], 0, 10);
    if ($date !== '' && $date > date('Y-m-d')) {
        return 'Results can be entered from ' . date('l j F Y', strtotime($date)) . ', once the tournament has taken place.';
    }
    return null;
}

// Load the tournament and check access and the entry window, or stop with an error
function resultsRequireEntry(PDO $pdo, $tournamentId) {
    $tournament = resultsLoadTournament($pdo, $tournamentId);
    if (!$tournament) {
        sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
    }
    $error = resultsAccessError($tournament);
    if ($error !== null) {
        sendJSON(['success' => false, 'message' => $error[0]], $error[1]);
    }
    $block = resultsEntryBlock($tournament);
    if ($block !== null) {
        sendJSON(['success' => false, 'message' => $block], 409);
    }
    return $tournament;
}

// The tournament's matches in one category: MatchId => IsApproved
function resultsCategoryMatchIds(PDO $pdo, $tournamentId, $categoryKey, $forUpdate) {
    $stmt = $pdo->prepare("SELECT MatchId, GroupName, IsApproved FROM Matches WHERE TournamentId = ?" . ($forUpdate ? " FOR UPDATE" : ""));
    $stmt->execute([(int) $tournamentId]);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        if (resultsCategoryKey($row['GroupName'] ?? '') === $categoryKey) {
            $ids[(int) $row['MatchId']] = (int) $row['IsApproved'];
        }
    }
    return $ids;
}

// Check the submitted matches. Final: all four players and a winner.
// Semi-final and quarter-finals: may be partly filled. The same registered
// player may appear only once. $alreadyIn: user ids already in these
// results (kept even if they have since been deactivated).
// Returns [rows, validPlayers (UserId => CategoryId)] or stops with an error.
function resultsReadMatches(PDO $pdo, $matches, array $alreadyIn = []) {
    if (!is_array($matches) || empty($matches)) {
        sendJSON(['success' => false, 'message' => 'Please enter the final'], 400);
    }
    if (count($matches) > 10) {
        sendJSON(['success' => false, 'message' => 'Too many matches for one category'], 400);
    }

    $rows = [];
    $perRound = ['QuarterFinal' => 0, 'SemiFinal' => 0, 'Final' => 0];
    $seen = [];
    foreach ($matches as $match) {
        if (!is_array($match)) {
            sendJSON(['success' => false, 'message' => 'Invalid match data'], 400);
        }
        $round = $match['roundType'] ?? '';
        if (!in_array($round, RESULTS_ROUNDS, true)) {
            sendJSON(['success' => false, 'message' => 'Invalid round type'], 400);
        }
        $perRound[$round]++;

        $slots = [];
        foreach (['player1Id', 'partner1Id', 'player2Id', 'partner2Id'] as $field) {
            $slot = resolvePlayerSlot($match[$field] ?? null);
            if ($slot['id'] !== null) {
                if (isset($seen[$slot['id']])) {
                    sendJSON(['success' => false, 'message' => 'The same player has been entered more than once. Each player can appear only once in a category.'], 400);
                }
                $seen[$slot['id']] = true;
            }
            $slots[] = $slot;
        }
        $filled = count(array_filter($slots, function ($s) { return $s['id'] !== null || $s['guestName'] !== null; }));

        $team1Score = filter_var($match['team1Score'] ?? 0, FILTER_VALIDATE_INT);
        $team2Score = filter_var($match['team2Score'] ?? 0, FILTER_VALIDATE_INT);
        $winnerTeam = filter_var($match['winnerTeam'] ?? 0, FILTER_VALIDATE_INT);
        if ($team1Score === false || $team2Score === false || $winnerTeam === false) {
            sendJSON(['success' => false, 'message' => 'Invalid match data'], 400);
        }
        if ($team1Score < 0 || $team1Score > 30 || $team2Score < 0 || $team2Score > 30) {
            sendJSON(['success' => false, 'message' => 'Invalid score values'], 400);
        }

        if ($round === 'Final') {
            if ($filled < 4) {
                sendJSON(['success' => false, 'message' => 'Both teams in the final need two players'], 400);
            }
            if (!in_array($winnerTeam, [1, 2], true)) {
                sendJSON(['success' => false, 'message' => 'Please mark the winners of the final'], 400);
            }
        } else {
            if ($filled === 0) {
                continue; // an empty match is simply left out
            }
            $winnerTeam = 0;
        }

        $rows[] = [
            'round' => $round,
            'slots' => $slots,
            'winner' => $winnerTeam,
            'score1' => $team1Score,
            'score2' => $team2Score,
        ];
    }
    if ($perRound['Final'] !== 1) {
        sendJSON(['success' => false, 'message' => 'Please enter the final'], 400);
    }
    if ($perRound['SemiFinal'] > 1 || $perRound['QuarterFinal'] > 2) {
        sendJSON(['success' => false, 'message' => 'Too many teams in a round'], 400);
    }

    // Registered players: active and not removed (or already in these results)
    $validPlayers = [];
    $ids = array_keys($seen);
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT UserId, CategoryId, IsActive, IsRemoved FROM Users WHERE UserId IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['UserId'];
            $usable = ((int) $row['IsActive'] === 1 && (int) $row['IsRemoved'] === 0) || in_array($id, $alreadyIn, true);
            if ($usable) {
                $validPlayers[$id] = $row['CategoryId'];
            }
        }
        foreach ($ids as $id) {
            if (!array_key_exists($id, $validPlayers)) {
                sendJSON(['success' => false, 'message' => 'One of the chosen players can no longer be selected. Please choose them again.'], 400);
            }
        }
    }
    return [$rows, $validPlayers];
}

// Insert one category's matches. With $approved, points are awarded through
// kbfAwardPoints() (api/points-rules.php: career only when the tournament
// was before the player's latest grade change or before this season).
// Returns the user ids that received points.
function resultsInsertMatches(PDO $pdo, $tournamentId, array $rows, $categoryName, $approved, array $validPlayers) {
    require_once __DIR__ . '/points-rules.php';
    $insertStmt = $pdo->prepare("
        INSERT INTO Matches (TournamentId, CategoryId,
                             Player1Id, Partner1Id, Player2Id, Partner2Id,
                             GuestPlayer1Name, GuestPartner1Name,
                             GuestPlayer2Name, GuestPartner2Name,
                             Team1Score, Team2Score, WinnerTeam,
                             IsApproved,
                             RoundType, GroupName, MatchDate)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $roundPointsMap = getRoundPointsMap($pdo);
    $awarded = [];

    foreach ($rows as $row) {
        list($p1, $p2, $p3, $p4) = $row['slots'];

        // CategoryId from the first registered player (stored for reference only)
        $categoryId = null;
        foreach ([$p1, $p2, $p3, $p4] as $slot) {
            if ($slot['id'] !== null && isset($validPlayers[$slot['id']])) {
                $categoryId = $validPlayers[$slot['id']];
                break;
            }
        }

        $insertStmt->execute([
            $tournamentId, $categoryId,
            $p1['id'], $p2['id'], $p3['id'], $p4['id'],
            $p1['guestName'], $p2['guestName'],
            $p3['guestName'], $p4['guestName'],
            $row['score1'], $row['score2'], $row['winner'],
            $approved ? 1 : 0,
            $row['round'],
            $categoryName === '' ? null : $categoryName,
        ]);
        $matchId = $pdo->lastInsertId();

        if (!$approved) {
            continue;
        }

        // Points per round (values sourced from RoundPoints table)
        $rp = $roundPointsMap[$row['round']] ?? [];
        $winnerPoints = $rp['Winner']['points'] ?? 0;
        $winnerReason = $rp['Winner']['reason'] ?? '';
        $loserPoints  = $rp['Loser']['points']  ?? 0;
        $loserReason  = $rp['Loser']['reason']  ?? '';
        $winnerTeam = $row['winner'];

        $team1Points = ($winnerTeam == 1 || $winnerTeam == 0) ? $winnerPoints : $loserPoints;
        $team1Reason = ($winnerTeam == 1 || $winnerTeam == 0) ? $winnerReason : $loserReason;
        $team2Points = ($winnerTeam == 2 || $winnerTeam == 0) ? $winnerPoints : $loserPoints;
        $team2Reason = ($winnerTeam == 2 || $winnerTeam == 0) ? $winnerReason : $loserReason;

        foreach ([
            [$p1, $team1Points, $team1Reason],
            [$p2, $team1Points, $team1Reason],
            [$p3, $team2Points, $team2Reason],
            [$p4, $team2Points, $team2Reason],
        ] as [$slot, $points, $reason]) {
            if ($slot['id'] !== null && $points > 0) {
                kbfAwardPoints($pdo, $slot['id'], $tournamentId, $matchId, $points, $reason);
                $awarded[] = $slot['id'];
            }
        }
    }
    return $awarded;
}

// Make sure a waiting row exists in the approvals queue
function resultsQueueForApproval(PDO $pdo, $tournamentId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ResultsApprovalQueue WHERE TournamentId = ? AND Status = 'Pending'");
    $stmt->execute([$tournamentId]);
    if ((int) $stmt->fetchColumn() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO ResultsApprovalQueue (TournamentId, SubmittedByAdminId, SubmittedDate)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$tournamentId, $_SESSION['user_id']]);
    }
}

// ============================================
// GET - Fetch matches
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // ---------- The results form ----------
        if (($_GET['action'] ?? '') === 'form') {
            if (!isLoggedIn()) {
                sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
            }
            $tournamentId = filter_var($_GET['tournamentId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$tournamentId) {
                sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
            }
            $t = resultsLoadTournament($pdo, $tournamentId);
            if (!$t) {
                sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
            }
            $error = resultsAccessError($t);
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error[0]], $error[1]);
            }
            $block = resultsEntryBlock($t);
            $manageAll = hasPermission('tournaments.manage_all');

            // Every match of the tournament, approved or waiting
            $stmt = $pdo->prepare("
                SELECT MatchId, RoundType, GroupName, WinnerTeam, IsApproved,
                       Player1Id, Partner1Id, Player2Id, Partner2Id,
                       GuestPlayer1Name, GuestPartner1Name, GuestPlayer2Name, GuestPartner2Name
                FROM Matches
                WHERE TournamentId = ?
                ORDER BY FIELD(RoundType, 'Final', 'SemiFinal', 'QuarterFinal'), MatchId ASC
            ");
            $stmt->execute([$tournamentId]);
            $matchRows = $stmt->fetchAll();

            // The registered players in those matches
            $userIds = [];
            foreach ($matchRows as $m) {
                foreach (['Player1Id', 'Partner1Id', 'Player2Id', 'Partner2Id'] as $f) {
                    if (!empty($m[$f])) {
                        $userIds[(int) $m[$f]] = true;
                    }
                }
            }
            $people = [];
            if ($userIds) {
                $ids = array_keys($userIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT u.UserId, u.PlayerId, u.FirstName, u.LastName, u.Location, u.ProfilePhoto,
                           u.IsRemoved, c.ShortName AS Grade
                    FROM Users u
                    LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
                    WHERE u.UserId IN ($placeholders)
                ");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll() as $u) {
                    $removed = (int) $u['IsRemoved'] === 1;
                    $people[(int) $u['UserId']] = [
                        'kind' => 'player',
                        'id' => (int) $u['UserId'],
                        'kbfId' => $removed ? '' : (string) $u['PlayerId'],
                        'first' => $removed ? 'Former' : (string) $u['FirstName'],
                        'last' => $removed ? 'member' : (string) $u['LastName'],
                        'city' => $removed ? '' : (string) $u['Location'],
                        'grade' => $removed ? '' : (string) $u['Grade'],
                        'photo' => $removed ? null : $u['ProfilePhoto'],
                    ];
                }
            }
            $slotOf = function ($id, $guest) use ($people) {
                if (!empty($id)) {
                    return $people[(int) $id] ?? ['kind' => 'player', 'id' => (int) $id, 'kbfId' => '', 'first' => 'Unknown', 'last' => 'player', 'city' => '', 'grade' => '', 'photo' => null];
                }
                $guest = trim((string) $guest);
                return $guest === '' ? null : ['kind' => 'guest', 'name' => $guest];
            };

            // Results grouped by category name
            $results = [];
            foreach ($matchRows as $m) {
                $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($m['GroupName'] ?? '')));
                $key = resultsCategoryKey($name);
                if (!isset($results[$key])) {
                    $results[$key] = ['name' => $name, 'approved' => false, 'waiting' => false, 'matches' => []];
                }
                if ((int) $m['IsApproved'] === 1) {
                    $results[$key]['approved'] = true;
                } else {
                    $results[$key]['waiting'] = true;
                }
                $results[$key]['matches'][] = [
                    'round' => $m['RoundType'],
                    'winner' => (int) $m['WinnerTeam'],
                    'teams' => [
                        [$slotOf($m['Player1Id'], $m['GuestPlayer1Name']), $slotOf($m['Partner1Id'], $m['GuestPartner1Name'])],
                        [$slotOf($m['Player2Id'], $m['GuestPlayer2Name']), $slotOf($m['Partner2Id'], $m['GuestPartner2Name'])],
                    ],
                ];
            }

            // Categories: from the tournament's categories text, then any
            // other names that already have results
            $categories = [];
            foreach (explode(',', (string) $t['CategoriesOffered']) as $part) {
                list($name, $nameError) = resultsCleanCategory(html_entity_decode($part, ENT_QUOTES, 'UTF-8'));
                if ($nameError === null && !isset($categories[resultsCategoryKey($name)])) {
                    $categories[resultsCategoryKey($name)] = $name;
                }
            }
            foreach ($results as $key => $r) {
                if (!isset($categories[$key])) {
                    $categories[$key] = $r['name'];
                }
            }
            $categoryList = [];
            foreach ($categories as $key => $name) {
                $r = $results[$key] ?? null;
                $state = $r === null ? 'none' : ($r['approved'] ? 'approved' : 'waiting');
                $categoryList[] = [
                    'key' => $key,
                    'name' => $name,
                    'state' => $state,
                    'canEdit' => $state !== 'none' && $block === null && ($manageAll || $state === 'waiting'),
                    'canDelete' => $state === 'waiting' && $block === null,
                    'matches' => $r === null ? [] : $r['matches'],
                ];
            }

            // Players to choose from: active, not removed, with a KBF ID
            $players = [];
            if ($block === null) {
                $stmt = $pdo->query("
                    SELECT u.UserId, u.PlayerId, u.FirstName, u.LastName, u.Location, u.ProfilePhoto,
                           c.ShortName AS Grade
                    FROM Users u
                    LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
                    WHERE u.IsActive = 1 AND u.IsRemoved = 0
                      AND u.PlayerId IS NOT NULL AND u.PlayerId <> ''
                    ORDER BY u.FirstName, u.LastName
                ");
                foreach ($stmt->fetchAll() as $u) {
                    $players[] = [
                        'id' => (int) $u['UserId'],
                        'kbfId' => (string) $u['PlayerId'],
                        'first' => (string) $u['FirstName'],
                        'last' => (string) $u['LastName'],
                        'city' => (string) $u['Location'],
                        'grade' => (string) $u['Grade'],
                        'photo' => $u['ProfilePhoto'],
                    ];
                }
            }

            $roundPoints = getRoundPointsMap($pdo);
            $pts = function ($round, $outcome) use ($roundPoints) {
                return (int) ($roundPoints[$round][$outcome]['points'] ?? 0);
            };

            sendJSON([
                'success' => true,
                'tournament' => [
                    'id' => (int) $t['TournamentId'],
                    'name' => (string) $t['Name'],
                    'date' => substr((string) $t['TournamentDate'], 0, 10),
                    'venue' => (string) $t['Venue'],
                    'town' => (string) $t['Location'],
                    'organiser' => (string) $t['OrganiserName'],
                    'status' => (string) $t['Status'],
                ],
                'canEnter' => $block === null,
                'blockedMessage' => $block,
                'autoApprove' => hasPermission('tournaments.approve'),
                'manageAll' => $manageAll,
                'points' => [
                    'quarter' => $pts('QuarterFinal', 'Winner'),
                    'semi' => $pts('SemiFinal', 'Winner'),
                    'winners' => $pts('Final', 'Winner'),
                    'runnersUp' => $pts('Final', 'Loser'),
                ],
                'categories' => $categoryList,
                'players' => $players,
            ]);
        }

        // ---------- Career-only notes for the chosen players ----------
        // Which players' points from this tournament would go to career
        // points only (decided here, never by the browser)
        if (($_GET['action'] ?? '') === 'pointsNotes') {
            if (!isLoggedIn()) {
                sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
            }
            $tournamentId = filter_var($_GET['tournamentId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$tournamentId) {
                sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
            }
            $t = resultsLoadTournament($pdo, $tournamentId);
            if (!$t) {
                sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
            }
            $error = resultsAccessError($t);
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error[0]], $error[1]);
            }
            $ids = [];
            foreach (explode(',', is_string($_GET['players'] ?? null) ? $_GET['players'] : '') as $raw) {
                $id = filter_var(trim($raw), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id) {
                    $ids[$id] = true;
                }
            }
            $ids = array_slice(array_keys($ids), 0, 16);
            require_once __DIR__ . '/points-rules.php';
            $notes = [];
            foreach (kbfCareerOnlyFor($pdo, $tournamentId, $ids) as $id => $reason) {
                $notes[] = [
                    'id' => (int) $id,
                    'kind' => $reason['kind'],
                    'date' => $reason['date'],
                    'grade' => $reason['grade'],
                ];
            }
            sendJSON(['success' => true, 'notes' => $notes]);
        }

        if (isset($_GET['tournamentId'])) {
            $tournamentId = filter_var($_GET['tournamentId'], FILTER_VALIDATE_INT);
            if (!$tournamentId) {
                sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
            }

            $stmt = $pdo->prepare("
    SELECT m.MatchId, m.RoundType, m.GroupName, m.Team1Score, m.Team2Score,
           m.WinnerTeam, m.MatchDate,
           m.Player1Id, m.Partner1Id, m.Player2Id, m.Partner2Id,
           m.GuestPlayer1Name, m.GuestPartner1Name,
           m.GuestPlayer2Name, m.GuestPartner2Name,
           cat.Name as CategoryName, cat.CategoryId,
           p1.FirstName as P1FirstName, p1.LastName as P1LastName, p1.PlayerId as P1PlayerId,
           p2.FirstName as Partner1FirstName, p2.LastName as Partner1LastName, p2.PlayerId as Partner1PlayerId,
           p3.FirstName as P2FirstName, p3.LastName as P2LastName, p3.PlayerId as P2PlayerId,
           p4.FirstName as Partner2FirstName, p4.LastName as Partner2LastName, p4.PlayerId as Partner2PlayerId,
           COALESCE(p1.IsRemoved, 0) as P1Removed, COALESCE(p2.IsRemoved, 0) as Partner1Removed,
           COALESCE(p3.IsRemoved, 0) as P2Removed, COALESCE(p4.IsRemoved, 0) as Partner2Removed
    FROM Matches m
    LEFT JOIN Categories cat ON m.CategoryId = cat.CategoryId
    LEFT JOIN Users p1 ON m.Player1Id = p1.UserId
    LEFT JOIN Users p2 ON m.Partner1Id = p2.UserId
    LEFT JOIN Users p3 ON m.Player2Id = p3.UserId
    LEFT JOIN Users p4 ON m.Partner2Id = p4.UserId
    WHERE m.TournamentId = ? AND m.IsApproved = 1
    ORDER BY FIELD(m.RoundType, 'Final', 'SemiFinal', 'QuarterFinal'),
         m.MatchId ASC
");
            $stmt->execute([$tournamentId]);
            // Removed players read "Former member"; staff keep the user ids
            $matches = kbfMaskRemovedInMatches($stmt->fetchAll(), isLoggedIn() && isStaff());

            sendJSON(['success' => true, 'matches' => $matches]);
        } else {
            sendJSON(['success' => false, 'message' => 'Tournament ID required'], 400);
        }
    } catch (PDOException $e) {
        error_log('Database error in matches.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST - Submit one category's results
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // tournaments.manage_all: any tournament.
    // tournaments.organise_own: only their own tournaments (checked below).
    if (!hasAnyPermission(['tournaments.manage_all', 'tournaments.organise_own'])) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['tournamentId']) || !isset($data['matches'])) {
        sendJSON(['success' => false, 'message' => 'Tournament ID and matches required'], 400);
    }

    $tournamentId = filter_var($data['tournamentId'], FILTER_VALIDATE_INT);
    if (!$tournamentId) {
        sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
    }

    list($categoryName, $categoryError) = resultsCleanCategory($data['categoryName'] ?? '');
    if ($categoryError !== null) {
        sendJSON(['success' => false, 'message' => $categoryError], 400);
    }

    try {
        // Approved tournament, dated today or earlier; permission and ownership
        $tournament = resultsRequireEntry($pdo, $tournamentId);
        list($rows, $validPlayers) = resultsReadMatches($pdo, $data['matches']);

        $pdo->beginTransaction();

        // Results submitted by someone with tournaments.approve are approved automatically
        $autoApprove = hasPermission('tournaments.approve');

        // One set of results per category
        if (resultsCategoryMatchIds($pdo, $tournamentId, resultsCategoryKey($categoryName), true)) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Results for ' . $categoryName . ' have already been submitted. Choose it from the categories to see or change them.'], 409);
        }

        $registeredPlayerIds = resultsInsertMatches($pdo, $tournamentId, $rows, $categoryName, $autoApprove, $validPlayers);

        // Recalculate stats only if auto-approved
        if ($autoApprove) {
            require_once __DIR__ . '/player-stats-calculator.php';
            foreach (array_unique($registeredPlayerIds) as $playerId) {
                calculatePlayerStats($pdo, $playerId);
            }
        }

        // Approval queue or auto-approve
        $queuedForApproval = false;
        if ($autoApprove) {
            $stmt = $pdo->prepare("UPDATE Tournaments SET Status = 'Completed' WHERE TournamentId = ?");
            $stmt->execute([$tournamentId]);
            $message = 'Results for ' . $categoryName . ' published.';
        } else {
            $queuedForApproval = true;
            resultsQueueForApproval($pdo, $tournamentId);
            $message = 'Results for ' . $categoryName . ' submitted for approval.';
        }

        $pdo->commit();

        auditLog('results_submitted', 'tournament', $tournamentId,
            'Results for: ' . $tournament['Name'] . '; category: ' . $categoryName . '; matches: ' . count($rows)
            . ($autoApprove ? '; approved automatically' : '; sent for approval'));

        // Notifications after the commit: anything held back (e.g. eligible
        // for promotion), then "results submitted" for the approvers
        kbfNotifyFlush();
        if ($queuedForApproval) {
            notify('results_submitted', [
                'values' => [
                    'tournament_name' => html_entity_decode((string) $tournament['Name'], ENT_QUOTES, 'UTF-8'),
                    'tournament_date' => date('l, d F Y', strtotime($tournament['TournamentDate'])),
                    'organiser_name' => html_entity_decode((string) $tournament['OrganiserName'], ENT_QUOTES, 'UTF-8'),
                    'count' => (string) count($rows),
                ],
                'relatedType' => 'tournament',
                'relatedId' => (int) $tournamentId,
            ]);
        }

        sendJSON(['success' => true, 'message' => $message, 'category' => $categoryName, 'approved' => $autoApprove]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        kbfNotifyDiscard();
        error_log('Match submission error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// DELETE - one category while waiting for approval
// (tournaments.manage_all, or the organiser of their own tournament),
// or one match (tournaments.manage_all)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['tournamentId'])) {
    $tournamentId = filter_var($_GET['tournamentId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$tournamentId) {
        sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
    }
    $categoryRaw = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
    $categoryKey = resultsCategoryKey($categoryRaw);

    try {
        $tournament = resultsRequireEntry($pdo, $tournamentId);

        $pdo->beginTransaction();
        $ids = resultsCategoryMatchIds($pdo, $tournamentId, $categoryKey, true);
        if (!$ids) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'There are no results for this category'], 404);
        }
        if (in_array(1, $ids, true)) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'These results are approved. Ask KBF if anything needs changing.'], 409);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM Matches WHERE MatchId IN ($placeholders) AND IsApproved = 0");
        $stmt->execute(array_keys($ids));

        // Nothing left waiting: take the tournament off the approvals list
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Matches WHERE TournamentId = ? AND IsApproved = 0");
        $stmt->execute([$tournamentId]);
        if ((int) $stmt->fetchColumn() === 0) {
            $stmt = $pdo->prepare("DELETE FROM ResultsApprovalQueue WHERE TournamentId = ? AND Status = 'Pending'");
            $stmt->execute([$tournamentId]);
        }

        $pdo->commit();

        auditLog('result_deleted', 'tournament', $tournamentId,
            'Results waiting for approval deleted for: ' . $tournament['Name'] . '; category: ' . ($categoryRaw === '' ? '(none)' : $categoryRaw)
            . '; matches: ' . count($ids));

        sendJSON(['success' => true, 'message' => 'The results were deleted.']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Category results deletion error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requirePermission('tournaments.manage_all');

    if (!isset($_GET['id'])) {
        sendJSON(['success' => false, 'message' => 'Match ID required'], 400);
    }

    $matchId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if (!$matchId) {
        sendJSON(['success' => false, 'message' => 'Invalid match ID'], 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT TournamentId, Player1Id, Partner1Id, Player2Id, Partner2Id, WinnerTeam, RoundType
            FROM Matches WHERE MatchId = ?
        ");
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();

        if (!$match) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Match not found'], 404);
        }

        // -----------------------------------------------
        // Reverse points before deleting history (mirrors PUT Step A):
        // sum the actual stored points this match awarded, per player, and
        // subtract from each player's live counter. Summing the rows we're
        // about to delete means we can't over- or under-reverse, and guests
        // are skipped automatically (they have no PlayerPointsHistory rows).
        // Career-only awards never added to season points, so only awards
        // that counted toward the season come off them; career points are
        // worked out again from what is left.
        // -----------------------------------------------
        require_once __DIR__ . '/points-rules.php';
        $stmt = $pdo->prepare("
            SELECT pph.PlayerId, " . kbfSeasonPointsSumSql($pdo, 'pph') . " AS TotalAwarded
            FROM PlayerPointsHistory pph
            WHERE pph.MatchId = ?
            GROUP BY pph.PlayerId
        ");
        $stmt->execute([$matchId]);
        $reversedPoints = $stmt->fetchAll();

        $deductStmt = $pdo->prepare("
            UPDATE Users SET TotalPoints = TotalPoints - ? WHERE UserId = ?
        ");
        foreach ($reversedPoints as $row) {
            $deductStmt->execute([$row['TotalAwarded'], $row['PlayerId']]);
        }

        $stmt = $pdo->prepare("DELETE FROM PlayerPointsHistory WHERE MatchId = ?");
        $stmt->execute([$matchId]);

        $stmt = $pdo->prepare("DELETE FROM Matches WHERE MatchId = ?");
        $stmt->execute([$matchId]);

        // Recalculate stats for the players whose points we just reversed, so a
        // deletion that drops someone below the promotion threshold clears their
        // eligibility flag immediately rather than waiting for the nightly job.
        require_once __DIR__ . '/player-stats-calculator.php';
        foreach ($reversedPoints as $row) {
            calculatePlayerStats($pdo, $row['PlayerId']);
        }

        $pdo->commit();

        auditLog('result_deleted', 'tournament', $match['TournamentId'], 'Match ' . $matchId . ' deleted');
        kbfNotifyFlush();

        sendJSON(['success' => true, 'message' => 'Match deleted successfully']);

    } catch (PDOException $e) {
        $pdo->rollBack();
        kbfNotifyDiscard();
        error_log('Match deletion error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// PUT - Replace one category's results
// tournaments.manage_all: any results. Approved results have their points
// reversed and worked out again, as before.
// Organisers: their own tournament's results while waiting for approval.
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (!hasAnyPermission(['tournaments.manage_all', 'tournaments.organise_own'])) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['tournamentId']) || !isset($data['matches'])) {
        sendJSON(['success' => false, 'message' => 'Tournament ID and matches required'], 400);
    }

    $tournamentId = filter_var($data['tournamentId'], FILTER_VALIDATE_INT);
    if (!$tournamentId) {
        sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
    }

    // The category being replaced (results without a category name have '')
    $categoryRaw = is_string($data['categoryName'] ?? null) ? $data['categoryName'] : '';
    $categoryName = trim((string) preg_replace('/\s+/u', ' ', strip_tags($categoryRaw)));
    $categoryKey = resultsCategoryKey($categoryName);

    try {
        $tournament = resultsRequireEntry($pdo, $tournamentId);

        $pdo->beginTransaction();

        $ids = resultsCategoryMatchIds($pdo, $tournamentId, $categoryKey, true);
        if (!$ids) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'There are no results for this category'], 404);
        }
        $approved = in_array(1, $ids, true);
        if ($approved && !hasPermission('tournaments.manage_all')) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'These results are approved. Ask KBF if anything needs changing.'], 403);
        }
        $matchIds = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($matchIds), '?'));

        // Players already in these results may stay even if since deactivated
        $stmt = $pdo->prepare("SELECT Player1Id, Partner1Id, Player2Id, Partner2Id FROM Matches WHERE MatchId IN ($placeholders)");
        $stmt->execute($matchIds);
        $alreadyIn = [];
        foreach ($stmt->fetchAll() as $row) {
            foreach ($row as $value) {
                if (!empty($value)) {
                    $alreadyIn[] = (int) $value;
                }
            }
        }
        list($rows, $validPlayers) = resultsReadMatches($pdo, $data['matches'], array_values(array_unique($alreadyIn)));

        // -----------------------------------------------
        // STEP A: Reverse the points these results gave, exactly as given:
        // season points lose only the awards that counted toward the season;
        // career points are worked out again from what is left
        // -----------------------------------------------
        require_once __DIR__ . '/points-rules.php';
        $stmt = $pdo->prepare("
            SELECT pph.PlayerId, " . kbfSeasonPointsSumSql($pdo, 'pph') . " as TotalAwarded
            FROM PlayerPointsHistory pph
            WHERE pph.MatchId IN ($placeholders)
            GROUP BY pph.PlayerId
        ");
        $stmt->execute($matchIds);
        $existingPoints = $stmt->fetchAll();

        $deductStmt = $pdo->prepare("
            UPDATE Users SET TotalPoints = TotalPoints - ? WHERE UserId = ?
        ");
        foreach ($existingPoints as $row) {
            $deductStmt->execute([$row['TotalAwarded'], $row['PlayerId']]);
        }

        $stmt = $pdo->prepare("DELETE FROM PlayerPointsHistory WHERE MatchId IN ($placeholders)");
        $stmt->execute($matchIds);

        // -----------------------------------------------
        // STEP B: Delete this category's matches
        // -----------------------------------------------
        $stmt = $pdo->prepare("DELETE FROM Matches WHERE MatchId IN ($placeholders)");
        $stmt->execute($matchIds);

        // -----------------------------------------------
        // STEP C: Insert the new matches (and award points when approved)
        // -----------------------------------------------
        $registeredPlayerIds = resultsInsertMatches($pdo, $tournamentId, $rows, $categoryName, $approved, $validPlayers);

        if ($approved) {
            // Recalculate stats for all involved players
            require_once __DIR__ . '/player-stats-calculator.php';
            foreach (array_unique($registeredPlayerIds) as $playerId) {
                calculatePlayerStats($pdo, $playerId);
            }
            // Also recalculate for players who lost points (were in old results but not new)
            foreach ($existingPoints as $row) {
                if (!in_array($row['PlayerId'], $registeredPlayerIds)) {
                    calculatePlayerStats($pdo, $row['PlayerId']);
                }
            }
        } else {
            resultsQueueForApproval($pdo, $tournamentId);
        }

        $pdo->commit();

        auditLog('results_edited', 'tournament', $tournamentId,
            'Results replaced for: ' . $tournament['Name'] . '; category: ' . ($categoryName === '' ? '(none)' : $categoryName)
            . '; matches: ' . count($rows) . ($approved ? '; points worked out again' : '; still waiting for approval'));
        kbfNotifyFlush();

        sendJSON(['success' => true, 'message' => $approved ? 'Results updated and points worked out again.' : 'Results updated. They are still waiting for approval.']);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        kbfNotifyDiscard();
        error_log('Match edit error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
