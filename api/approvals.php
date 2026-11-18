<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/auth/email-config.php';
require_once __DIR__ . '/report-helpers.php';

// ============================================
// APPROVALS API
// Registrations need players.approve_registrations (a different grade also
// needs players.change_grades); tournaments and results need
// tournaments.approve. Order of checks: logged in, CSRF on writes, then
// the permission. Emails go through notify() after the change is saved.
//
//   GET ?action=meta                      tab counts, permissions, grades, reject reasons
//   GET ?action=queue&type=registrations|tournaments|results
//                                         q, sort (oldest|newest|name), grade, offset (50 a page)
//   GET ?action=registration&id=          one registration to review
//   GET ?action=tournament&id=            one tournament to review
//   GET ?action=results&id=               one set of results (ApprovalId) to review
//   GET ?action=history                   type, decision, person, range (7|30|season|all|custom),
//                                         from, to, q, offset (50 a page)
//   GET ?action=historyCsv                the same filters, as a CSV download
//   GET (no action)                       the earlier combined list (kept for older callers)
//   POST {type, id, action: approve|reject, categoryId?, reasonKey?, message?, comments?}
//   POST {action: 'approveSelected', ids: [registration ids]}
//                                         players.approve_registrations and players.change_grades
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
// REJECTION REASONS: key => [label, default message]
// The message fills {reason} in the rejection email.
// ============================================
const APPROVAL_REASONS = [
    'registration' => [
        'already_registered' => ['Already registered under another account', 'It looks like you are already registered with KBF. Please contact us if you need help with your account.'],
        'not_confirmed' => ['Details could not be confirmed', 'We could not confirm the details you gave. Please register again with your full name, city and a mobile number we can reach.'],
        'not_eligible' => ['Not eligible to join KBF', 'Unfortunately you are not eligible to join KBF at this time.'],
        'other' => ['Something else', ''],
    ],
    'tournament' => [
        'incomplete' => ['Details are incomplete', 'Some of the tournament details are missing. Please add them and submit it again.'],
        'duplicate' => ['Duplicate of another tournament', 'This tournament is already listed on the KBF site, so this copy has not been approved.'],
        'results_incorrect' => ['Results look incorrect', 'Some of the results look incorrect. Please check the players and placings and submit them again.'],
        'other' => ['Something else', ''],
    ],
];

const APPROVAL_PAGE_SIZE = 50;

// ============================================
// HELPERS
// ============================================
function apText($value) {
    return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
}

function apName($first, $last) {
    return trim(apText($first) . ' ' . apText($last));
}

function apRequireLogin() {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }
}

function apRequire($permission) {
    if (!hasPermission($permission)) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
}

function apIdParam() {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        sendJSON(['success' => false, 'message' => 'Invalid ID'], 400);
    }
    return (int) $id;
}

function apSearchParam() {
    $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    return function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
}

function apSortParam() {
    $sort = $_GET['sort'] ?? 'oldest';
    return in_array($sort, ['oldest', 'newest', 'name'], true) ? $sort : 'oldest';
}

// Possible existing members for a registration (alias pr): active, not
// removed, same email, or the same first name, surname and city ignoring
// case and spaces
const AP_MEMBER_EMAIL_SQL = "(pr.Email IS NOT NULL AND TRIM(pr.Email) <> '' AND LOWER(TRIM(u.Email)) = LOWER(TRIM(pr.Email)))";
const AP_MEMBER_NAME_SQL = "(REPLACE(LOWER(u.FirstName), ' ', '') = REPLACE(LOWER(pr.FirstName), ' ', '')
        AND REPLACE(LOWER(u.LastName), ' ', '') = REPLACE(LOWER(pr.LastName), ' ', '')
        AND TRIM(COALESCE(pr.Location, '')) <> ''
        AND REPLACE(LOWER(COALESCE(u.Location, '')), ' ', '') = REPLACE(LOWER(pr.Location), ' ', ''))";

function apPossibleMemberSql() {
    return "EXISTS (SELECT 1 FROM Users u WHERE u.IsActive = 1 AND u.IsRemoved = 0
        AND (" . AP_MEMBER_EMAIL_SQL . " OR " . AP_MEMBER_NAME_SQL . "))";
}

function apPossibleMembers(PDO $pdo, $registrationId) {
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.FirstName, u.LastName, u.PlayerId, u.Location,
               " . AP_MEMBER_EMAIL_SQL . " AS ByEmail,
               " . AP_MEMBER_NAME_SQL . " AS ByName
        FROM PlayerRegistrations pr
        JOIN Users u ON u.IsActive = 1 AND u.IsRemoved = 0
             AND (" . AP_MEMBER_EMAIL_SQL . " OR " . AP_MEMBER_NAME_SQL . ")
        WHERE pr.RegistrationId = ?
        ORDER BY u.LastName, u.FirstName
        LIMIT 20
    ");
    $stmt->execute([(int) $registrationId]);
    return array_map(function ($r) {
        return [
            'id' => (int) $r['UserId'],
            'name' => apName($r['FirstName'], $r['LastName']),
            'kbfId' => (string) $r['PlayerId'],
            'city' => apText($r['Location']),
            'byEmail' => (int) $r['ByEmail'] === 1,
            'byName' => (int) $r['ByName'] === 1,
        ];
    }, $stmt->fetchAll());
}

function apCounts(PDO $pdo) {
    $counts = [];
    if (hasPermission('players.approve_registrations')) {
        $counts['registrations'] = (int) $pdo->query("SELECT COUNT(*) FROM PlayerRegistrations WHERE Status = 'Pending'")->fetchColumn();
    }
    if (hasPermission('tournaments.approve')) {
        $counts['tournaments'] = (int) $pdo->query("SELECT COUNT(*) FROM Tournaments WHERE Status = 'Pending'")->fetchColumn();
        $counts['results'] = (int) $pdo->query("SELECT COUNT(*) FROM ResultsApprovalQueue WHERE Status = 'Pending'")->fetchColumn();
    }
    return $counts;
}

function apReasonsFor($type) {
    $key = $type === 'registration' ? 'registration' : 'tournament';
    $out = [];
    foreach (APPROVAL_REASONS[$key] as $k => $r) {
        $out[] = ['key' => $k, 'label' => $r[0], 'message' => $r[1]];
    }
    return $out;
}

// Results waiting for approval: every team with its players and the
// points each registered player will receive
function apResultsTeams(PDO $pdo, $tournamentId) {
    $stmt = $pdo->prepare("
        SELECT m.MatchId, m.RoundType, m.GroupName, m.WinnerTeam,
               m.Player1Id, m.Partner1Id, m.Player2Id, m.Partner2Id,
               m.GuestPlayer1Name, m.GuestPartner1Name, m.GuestPlayer2Name, m.GuestPartner2Name
        FROM Matches m
        WHERE m.TournamentId = ? AND m.IsApproved = 0
        ORDER BY m.GroupName, FIELD(m.RoundType, 'Final', 'SemiFinal', 'QuarterFinal'), m.MatchId
    ");
    $stmt->execute([(int) $tournamentId]);
    $matches = $stmt->fetchAll();

    $ids = [];
    foreach ($matches as $m) {
        foreach (['Player1Id', 'Partner1Id', 'Player2Id', 'Partner2Id'] as $f) {
            if (!empty($m[$f])) {
                $ids[(int) $m[$f]] = true;
            }
        }
    }
    $people = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $p = $pdo->prepare("SELECT UserId, FirstName, LastName, PlayerId, IsRemoved FROM Users WHERE UserId IN ($placeholders)");
        $p->execute(array_keys($ids));
        foreach ($p->fetchAll() as $u) {
            $removed = (int) $u['IsRemoved'] === 1;
            $people[(int) $u['UserId']] = [
                'name' => $removed ? 'Former member' : apName($u['FirstName'], $u['LastName']),
                'kbfId' => $removed ? '' : (string) $u['PlayerId'],
            ];
        }
    }

    // Players whose points would go to career points only (same rule as the award)
    require_once __DIR__ . '/points-rules.php';
    $careerOnly = kbfCareerOnlyFor($pdo, $tournamentId, array_keys($ids));

    $map = getRoundPointsMap($pdo);
    $roundLabels = ['Final' => 'Final', 'SemiFinal' => 'Semi-final', 'QuarterFinal' => 'Quarter-final'];
    $categories = [];
    $counter = [];
    foreach ($matches as $m) {
        $category = trim(apText($m['GroupName'] ?? ''));
        $key = strtolower($category);
        if (!isset($categories[$key])) {
            $categories[$key] = ['name' => $category, 'teams' => []];
            $counter[$key] = [];
        }
        $rp = $map[$m['RoundType']] ?? [];
        $w = (int) $m['WinnerTeam'];
        foreach ([1, 2] as $team) {
            $won = $w === $team || $w === 0;
            $outcome = $won ? ($rp['Winner'] ?? []) : ($rp['Loser'] ?? []);
            $slots = $team === 1
                ? [[$m['Player1Id'], $m['GuestPlayer1Name']], [$m['Partner1Id'], $m['GuestPartner1Name']]]
                : [[$m['Player2Id'], $m['GuestPlayer2Name']], [$m['Partner2Id'], $m['GuestPartner2Name']]];
            $players = [];
            $teamPoints = (int) ($outcome['points'] ?? 0);
            foreach ($slots as $s) {
                if (!empty($s[0])) {
                    $info = $people[(int) $s[0]] ?? ['name' => 'Unknown player', 'kbfId' => ''];
                    $reason = $careerOnly[(int) $s[0]] ?? null;
                    $players[] = [
                        'name' => $info['name'], 'kbfId' => $info['kbfId'], 'guest' => false,
                        'careerOnly' => $reason !== null && $teamPoints > 0,
                        'note' => ($reason !== null && $teamPoints > 0) ? kbfCareerOnlyNote($info['name'], $reason, $teamPoints) : '',
                    ];
                } elseif (trim((string) $s[1]) !== '') {
                    $players[] = ['name' => apText($s[1]), 'kbfId' => '', 'guest' => true, 'careerOnly' => false, 'note' => ''];
                }
            }
            if (!$players) {
                continue;
            }
            $round = $roundLabels[$m['RoundType']] ?? (string) $m['RoundType'];
            $counter[$key][$round] = ($counter[$key][$round] ?? 0) + 1;
            $categories[$key]['teams'][] = [
                'round' => $round,
                'label' => $round . ' team ' . $counter[$key][$round],
                'placing' => (string) ($outcome['reason'] ?? ''),
                'points' => (int) ($outcome['points'] ?? 0),
                'players' => $players,
            ];
        }
    }
    return array_values($categories);
}

// ============================================
// ONE DECISION
// Returns ['success', 'message', 'code', 'emailStatus'].
// $reasonKey and $message: rejection reason; $message fills {reason}.
// ============================================
function apDecide(PDO $pdo, $type, $id, $action, $categoryInput, $reasonKey, $message) {
    $fail = function ($text, $code) use ($pdo) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $text, 'code' => $code, 'emailStatus' => null];
    };

    // Reason: a known key and a message up to 1,000 characters
    $reasonLabel = null;
    if ($action === 'reject' && $reasonKey !== null && $reasonKey !== '') {
        $group = $type === 'registration' ? 'registration' : 'tournament';
        if (!isset(APPROVAL_REASONS[$group][$reasonKey])) {
            return $fail('Please choose a reason', 400);
        }
        $reasonLabel = APPROVAL_REASONS[$group][$reasonKey][0];
        if (trim((string) $message) === '') {
            if ($reasonKey === 'other') {
                return $fail('Please write the message for "Something else"', 400);
            }
            $message = APPROVAL_REASONS[$group][$reasonKey][1];
        }
    }
    $comments = ($message !== null && trim((string) $message) !== '') ? sanitizeInput((string) $message) : null;
    if ($comments && strlen(apText($comments)) > 1000) {
        return $fail('The message must be 1,000 characters or fewer', 400);
    }
    $logComments = $comments === null ? null : ($reasonLabel !== null ? $reasonLabel . ': ' . $comments : $comments);

    // Notification to send once the decision is saved: [eventKey, context, audience]
    $pendingNotify = null;

    try {
        $pdo->beginTransaction();

        if ($type === 'registration') {
            $status = ($action === 'approve') ? 'Approved' : 'Rejected';

            $stmt = $pdo->prepare("
                SELECT * FROM PlayerRegistrations
                WHERE RegistrationId = ? AND Status = 'Pending'
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $registration = $stmt->fetch();
            if (!$registration) {
                return $fail('Registration not found or already processed', 404);
            }

            $gradeChanged = false;
            $gradeShort = '';
            if ($status === 'Approved') {
                // Check for duplicate email before approving
                $stmt = $pdo->prepare("SELECT UserId FROM Users WHERE Email = ?");
                $stmt->execute([$registration['Email']]);
                if ($stmt->fetch()) {
                    return $fail('Email already exists in system', 400);
                }

                // Changing the grade during approval also needs players.change_grades
                $finalCategoryId = $registration['CategoryId'];
                if ($categoryInput !== null && $categoryInput !== '') {
                    $categoryId = filter_var($categoryInput, FILTER_VALIDATE_INT);
                    if ($categoryId && (int) $categoryId !== (int) $registration['CategoryId']) {
                        if (!hasPermission('players.change_grades')) {
                            return $fail('You do not have permission to change grades', 403);
                        }
                        if (categoryExists($pdo, $categoryId)) {
                            $finalCategoryId = $categoryId;
                            $gradeChanged = true;
                        }
                    }
                }

                // Move registration to Users table
                $stmt = $pdo->prepare("
                    INSERT INTO Users
                    (PlayerId, Email, PasswordHash, FirstName, LastName, Location, PostCode,
                     PhoneNumber, YearOfBirth, ProfilePhoto, Role, CategoryId, TotalPoints, IsActive, CreatedDate,
                     TermsVersion, TermsAcceptedDate)
                    VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'Player', ?, 0, 1, NOW(), ?, ?)
                ");
                $stmt->execute([
                    $registration['PlayerId'],
                    $registration['Email'],
                    $registration['FirstName'],
                    $registration['LastName'],
                    $registration['Location'],
                    $registration['PostCode'],
                    $registration['PhoneNumber'],
                    $registration['YearOfBirth'],
                    $registration['ProfilePhoto'],
                    $finalCategoryId,
                    // What the player agreed to when registering (NULL = an earlier version)
                    $registration['TermsVersion'] ?? null,
                    $registration['TermsAcceptedDate'] ?? null
                ]);
                $userId = $pdo->lastInsertId();

                // Create PlayerPerformanceTracker entry
                $stmt = $pdo->prepare("
                    INSERT INTO PlayerPerformanceTracker
                    (PlayerId, CategoryId, FinalsReached, SemiFinalsReached, GroupStageFailures,
                     TotalTournamentsPlayed, Last6MonthsPoints, CareerPoints,
                     EligibleForPromotion, FlaggedForDemotion, LastCalculatedDate)
                    VALUES (?, ?, 0, 0, 0, 0, 0, 0, 0, 0, NOW())
                ");
                $stmt->execute([$userId, $finalCategoryId]);

                // Move profile photo from registrations to profiles folder if exists
                if ($registration['ProfilePhoto']) {
                    // SECURITY: Validate photo path
                    if (preg_match('#^/uploads/registrations/[a-zA-Z0-9_]+\.(jpg|jpeg|png|webp)$#', $registration['ProfilePhoto'])) {
                        $oldPath = __DIR__ . '/..' . $registration['ProfilePhoto'];
                        if (file_exists($oldPath)) {
                            // SECURITY: Generate safe filename
                            $extension = pathinfo($oldPath, PATHINFO_EXTENSION);
                            $newFilename = 'player_' . $registration['PlayerId'] . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                            $newPath = __DIR__ . '/../uploads/profiles/' . $newFilename;

                            $profilesDir = __DIR__ . '/../uploads/profiles/';
                            if (!is_dir($profilesDir)) {
                                mkdir($profilesDir, 0755, true);
                            }

                            if (copy($oldPath, $newPath)) {
                                $stmt = $pdo->prepare("UPDATE Users SET ProfilePhoto = ? WHERE UserId = ?");
                                $stmt->execute(['/uploads/profiles/' . $newFilename, $userId]);
                                unlink($oldPath);
                            }
                        }
                    }
                }

                // Approval email to the new player: sent after the commit below
                $gradeStmt = $pdo->prepare("SELECT Name, ShortName FROM Categories WHERE CategoryId = ?");
                $gradeStmt->execute([$finalCategoryId]);
                $gradeRow = $gradeStmt->fetch();
                $gradeShort = $gradeRow ? (string) $gradeRow['ShortName'] : '';
                $pendingNotify = ['registration_approved', [
                    'person' => (int) $userId,
                    'values' => [
                        'kbf_id' => $registration['PlayerId'],
                        'grade' => $gradeRow ? (string) $gradeRow['Name'] : '',
                    ],
                    'relatedType' => 'user',
                    'relatedId' => (int) $userId,
                ], 'person'];

                $resultMessage = 'Registration approved and player created successfully';
            } else {
                // Rejected - delete profile photo if exists
                if ($registration['ProfilePhoto']) {
                    if (preg_match('#^/uploads/registrations/[a-zA-Z0-9_]+\.(jpg|jpeg|png|webp)$#', $registration['ProfilePhoto'])) {
                        $photoPath = __DIR__ . '/..' . $registration['ProfilePhoto'];
                        if (file_exists($photoPath)) {
                            unlink($photoPath);
                        }
                    }
                }

                // Rejection email to the applicant: sent after the commit below
                $pendingNotify = ['registration_rejected', [
                    'person' => [
                        'email' => $registration['Email'],
                        'firstName' => $registration['FirstName'],
                        'lastName' => $registration['LastName'],
                    ],
                    'values' => [
                        'reason' => $comments ? apText($comments) : '',
                    ],
                    'relatedType' => 'registration',
                    'relatedId' => (int) $id,
                ], 'person'];

                $resultMessage = 'Registration rejected';
            }

            // Update registration status
            $stmt = $pdo->prepare("
                UPDATE PlayerRegistrations
                SET Status = ?, ReviewedByAdminId = ?, ReviewedDate = NOW(), ReviewComments = ?
                WHERE RegistrationId = ?
            ");
            $stmt->execute([$status, $_SESSION['user_id'], $comments, $id]);

            // Log the approval action (the grade given is kept in the details)
            $logDetails = "Player: {$registration['FirstName']} {$registration['LastName']}, ID: {$registration['PlayerId']}, Location: {$registration['Location']}"
                . ($status === 'Approved' && $gradeShort !== '' ? ", Grade: {$gradeShort}" : '');
            $stmt = $pdo->prepare("
                INSERT INTO ApprovalLogs
                (LogType, Action, ReferenceId, ReferenceName, ReferenceDetails, ApprovedByAdminId, ApprovedByAdminName, Comments)
                VALUES ('Registration', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $status,
                $id,
                $registration['FirstName'] . ' ' . $registration['LastName'],
                $logDetails,
                $_SESSION['user_id'],
                $_SESSION['name'] ?? '',
                $logComments
            ]);

            $auditDetails = 'Registration ' . strtolower($status) . ': ' . $registration['FirstName'] . ' ' . $registration['LastName'];
            if ($status === 'Approved' && $gradeChanged) {
                $auditDetails .= ' (grade changed during approval)';
            }
            $auditPending = [[$status === 'Approved' ? 'registration_approved' : 'registration_rejected', 'registration', $id, $auditDetails]];
            if ($status === 'Approved' && $gradeChanged) {
                $auditPending[] = ['grade_changed', 'user', $userId, 'Grade set during registration approval'];
            }

        } elseif ($type === 'tournament') {
            $status = ($action === 'approve') ? 'Approved' : 'Rejected';

            $stmt = $pdo->prepare("
                SELECT t.*, u.FirstName, u.LastName
                FROM Tournaments t
                JOIN Users u ON t.CreatedByAdminId = u.UserId
                WHERE t.TournamentId = ? AND t.Status = 'Pending'
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $tournament = $stmt->fetch();
            if (!$tournament) {
                return $fail('Tournament not found or already processed', 404);
            }

            $stmt = $pdo->prepare("
                UPDATE Tournaments
                SET Status = ?, ApprovedByAdminId = ?, ApprovedDate = NOW()
                WHERE TournamentId = ?
            ");
            $stmt->execute([$status, $_SESSION['user_id'], $id]);

            // Email to the tournament's creator: sent after the commit below
            $pendingNotify = [$status === 'Approved' ? 'tournament_approved' : 'tournament_rejected', [
                'organiser' => (int) $tournament['CreatedByAdminId'],
                'values' => [
                    'tournament_name' => apText($tournament['Name']),
                    'tournament_date' => date('l, d F Y', strtotime($tournament['TournamentDate'])),
                    'reason' => $comments ? apText($comments) : '',
                ],
                'relatedType' => 'tournament',
                'relatedId' => (int) $id,
            ], 'organiser'];

            $logDetails = "Tournament: {$tournament['Name']}, Date: " . date('d M Y', strtotime($tournament['TournamentDate'])) . ", Venue: {$tournament['Venue']}";
            $stmt = $pdo->prepare("
                INSERT INTO ApprovalLogs
                (LogType, Action, ReferenceId, ReferenceName, ReferenceDetails, ApprovedByAdminId, ApprovedByAdminName, Comments)
                VALUES ('Tournament', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $status,
                $id,
                $tournament['Name'],
                $logDetails,
                $_SESSION['user_id'],
                $_SESSION['name'] ?? '',
                $logComments
            ]);

            $auditPending = [[$status === 'Approved' ? 'tournament_approved' : 'tournament_rejected', 'tournament', $id, 'Tournament: ' . $tournament['Name']]];
            $resultMessage = "Tournament $status";

        } else { // results
            $status = ($action === 'approve') ? 'Approved' : 'Rejected';

            $stmt = $pdo->prepare("
                SELECT ra.*, t.Name as TournamentName, t.TournamentDate
                FROM ResultsApprovalQueue ra
                JOIN Tournaments t ON ra.TournamentId = t.TournamentId
                WHERE ra.ApprovalId = ? AND ra.Status = 'Pending'
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            if (!$result) {
                return $fail('Results not found or already processed', 404);
            }

            $stmt = $pdo->prepare("
                UPDATE ResultsApprovalQueue
                SET Status = ?, ReviewedByAdminId = ?, ReviewedDate = NOW(), ReviewComments = ?
                WHERE ApprovalId = ?
            ");
            $stmt->execute([$status, $_SESSION['user_id'], $comments, $id]);

            // Email to the tournament's creator: sent after the commit below
            $creatorStmt = $pdo->prepare("SELECT CreatedByAdminId FROM Tournaments WHERE TournamentId = ?");
            $creatorStmt->execute([$result['TournamentId']]);
            $pendingNotify = [$status === 'Approved' ? 'results_approved' : 'results_rejected', [
                'organiser' => (int) $creatorStmt->fetchColumn(),
                'values' => [
                    'tournament_name' => apText($result['TournamentName']),
                    'tournament_date' => date('l, d F Y', strtotime($result['TournamentDate'])),
                    'reason' => $comments ? apText($comments) : '',
                ],
                'relatedType' => 'tournament',
                'relatedId' => (int) $result['TournamentId'],
            ], 'organiser'];

            if ($status === 'Approved') {
                // The matches waiting for approval. Results are submitted one
                // category at a time, so categories approved earlier already have
                // their points and are left alone.
                $stmt = $pdo->prepare("
                    SELECT MatchId, Player1Id, Partner1Id, Player2Id, Partner2Id,
                           WinnerTeam, RoundType
                    FROM Matches
                    WHERE TournamentId = ? AND IsApproved = 0
                    FOR UPDATE
                ");
                $stmt->execute([$result['TournamentId']]);
                $matches = $stmt->fetchAll();

                // Mark them as approved
                $stmt = $pdo->prepare("
                    UPDATE Matches SET IsApproved = 1 WHERE TournamentId = ? AND IsApproved = 0
                ");
                $stmt->execute([$result['TournamentId']]);

                // Points through the shared rule (api/points-rules.php): career
                // only when the tournament was before the player's latest grade
                // change or before this season; all inside this transaction
                require_once __DIR__ . '/points-rules.php';

                $registeredPlayerIds = [];
                foreach ($matches as $match) {
                    // Points per round (values sourced from RoundPoints table)
                    $roundPointsMap = getRoundPointsMap($pdo);
                    $rp = $roundPointsMap[$match['RoundType']] ?? [];
                    $winnerPoints = $rp['Winner']['points'] ?? 0;
                    $winnerReason = $rp['Winner']['reason'] ?? '';
                    $loserPoints  = $rp['Loser']['points']  ?? 0;
                    $loserReason  = $rp['Loser']['reason']  ?? '';

                    $winnerTeam = $match['WinnerTeam'];
                    $team1Points = ($winnerTeam == 1 || $winnerTeam == 0) ? $winnerPoints : $loserPoints;
                    $team1Reason = ($winnerTeam == 1 || $winnerTeam == 0) ? $winnerReason : $loserReason;
                    $team2Points = ($winnerTeam == 2 || $winnerTeam == 0) ? $winnerPoints : $loserPoints;
                    $team2Reason = ($winnerTeam == 2 || $winnerTeam == 0) ? $winnerReason : $loserReason;

                    $slotPointPairs = [
                        [$match['Player1Id'],  $team1Points, $team1Reason],
                        [$match['Partner1Id'], $team1Points, $team1Reason],
                        [$match['Player2Id'],  $team2Points, $team2Reason],
                        [$match['Partner2Id'], $team2Points, $team2Reason],
                    ];

                    foreach ($slotPointPairs as [$playerId, $points, $reason]) {
                        if ($playerId !== null && $points > 0) {
                            kbfAwardPoints($pdo, $playerId, $result['TournamentId'], $match['MatchId'], $points, $reason);
                            $registeredPlayerIds[] = $playerId;
                        }
                    }
                }

                // Recalculate stats for all involved players
                require_once __DIR__ . '/player-stats-calculator.php';
                foreach (array_unique($registeredPlayerIds) as $playerId) {
                    calculatePlayerStats($pdo, $playerId);
                }

                // Mark tournament as completed
                $stmt = $pdo->prepare("UPDATE Tournaments SET Status = 'Completed' WHERE TournamentId = ?");
                $stmt->execute([$result['TournamentId']]);
            } else {
                // Rejected - delete the pending matches so they don't show publicly
                $stmt = $pdo->prepare("DELETE FROM Matches WHERE TournamentId = ? AND IsApproved = 0");
                $stmt->execute([$result['TournamentId']]);
            }

            $logDetails = "Tournament: {$result['TournamentName']}, Date: " . date('d M Y', strtotime($result['TournamentDate']));
            $stmt = $pdo->prepare("
                INSERT INTO ApprovalLogs
                (LogType, Action, ReferenceId, ReferenceName, ReferenceDetails, ApprovedByAdminId, ApprovedByAdminName, Comments)
                VALUES ('Results', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $status,
                $id,
                $result['TournamentName'],
                $logDetails,
                $_SESSION['user_id'],
                $_SESSION['name'] ?? '',
                $logComments
            ]);

            $auditPending = [[$status === 'Approved' ? 'results_approved' : 'results_rejected', 'tournament', $result['TournamentId'], 'Results for: ' . $result['TournamentName']]];
            $resultMessage = "Results $status";
        }

        $pdo->commit();

        foreach ($auditPending as $a) {
            auditLog($a[0], $a[1], $a[2], $a[3]);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        kbfNotifyDiscard();
        error_log('Approval error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred', 'code' => 500, 'emailStatus' => null];
    }

    // The decision is saved. Only now send notifications (including any held
    // back during the transaction, such as "eligible for promotion"); a
    // problem never undoes the decision, and the message says what happened.
    kbfNotifyFlush();
    $emailStatus = null;
    if ($pendingNotify !== null) {
        $results = notify($pendingNotify[0], $pendingNotify[1]);
        $audience = $pendingNotify[2];
        $emailStatus = $results[$audience] ?? null;
        $outcome = kbfNotifyOutcomeText($results, $audience);
        $resultMessage .= '.' . ($outcome !== '' ? ' ' . $outcome : '');
    } else {
        $resultMessage .= '.';
    }

    return ['success' => true, 'message' => $resultMessage, 'code' => 200, 'emailStatus' => $emailStatus];
}

// ============================================
// HISTORY: ApprovalLogs, plus decisions recorded only in AuditLog,
// without duplicates
// ============================================
const AP_HISTORY_TYPES = [
    'registration' => ['Registration', ['registration_approved', 'registration_rejected'], 'players.approve_registrations'],
    'tournament' => ['Tournament', ['tournament_approved', 'tournament_rejected'], 'tournaments.approve'],
    'results' => ['Results', ['results_approved', 'results_rejected'], 'tournaments.approve'],
];
const AP_TYPE_LABELS = ['Registration' => 'Player registration', 'Tournament' => 'Tournament', 'Results' => 'Results'];

// The types this person can approve: [key => LogType]
function apHistoryTypes() {
    $types = [];
    foreach (AP_HISTORY_TYPES as $key => $t) {
        if (hasPermission($t[2])) {
            $types[$key] = $t[0];
        }
    }
    return $types;
}

// Entries as one table (fixed strings only; the IN lists come from the
// whitelist above)
function apHistoryUnionSql(array $types) {
    $logTypes = "'" . implode("','", array_values($types)) . "'";
    $actions = [];
    foreach (array_keys($types) as $key) {
        $actions = array_merge($actions, AP_HISTORY_TYPES[$key][1]);
    }
    $auditActions = "'" . implode("','", $actions) . "'";
    $decisionCase = "CASE WHEN a.Action LIKE '%approved' THEN 'Approved' ELSE 'Rejected' END";

    return "
        SELECT 'log' AS Source, al.LogId AS EntryId, al.LogDate AS At, al.LogType AS Type, al.ReferenceId AS RefId,
               al.ReferenceName AS ItemName, al.Action AS Decision, al.ApprovedByAdminId AS DeciderId,
               al.ApprovedByAdminName AS DeciderLogged, al.ReferenceDetails AS Details, al.Comments AS Comment
        FROM ApprovalLogs al
        WHERE al.Action IN ('Approved', 'Rejected') AND al.LogType IN ($logTypes)
        UNION ALL
        SELECT 'audit', a.AuditId, a.CreatedDate,
               CASE WHEN a.Action LIKE 'registration%' THEN 'Registration'
                    WHEN a.Action LIKE 'tournament%' THEN 'Tournament' ELSE 'Results' END,
               a.TargetId,
               CASE WHEN a.Action LIKE 'registration%'
                    THEN (SELECT CONCAT(pr.FirstName, ' ', pr.LastName) FROM PlayerRegistrations pr WHERE pr.RegistrationId = a.TargetId)
                    ELSE (SELECT t.Name FROM Tournaments t WHERE t.TournamentId = a.TargetId) END,
               $decisionCase,
               a.ActorUserId, NULL, a.Details, NULL
        FROM AuditLog a
        WHERE a.Action IN ($auditActions)
          AND NOT EXISTS (
              SELECT 1 FROM ApprovalLogs d
              WHERE d.Action = $decisionCase
                AND ABS(TIMESTAMPDIFF(SECOND, d.LogDate, a.CreatedDate)) <= 600
                AND (
                    (a.Action LIKE 'registration%' AND d.LogType = 'Registration' AND d.ReferenceId = a.TargetId)
                    OR (a.Action LIKE 'tournament%' AND d.LogType = 'Tournament' AND d.ReferenceId = a.TargetId)
                    OR (a.Action LIKE 'results%' AND d.LogType = 'Results'
                        AND d.ReferenceId IN (SELECT q.ApprovalId FROM ResultsApprovalQueue q WHERE q.TournamentId = a.TargetId))
                )
          )";
}

// [sql, params, filters] for the history list with the request's filters
function apHistoryQuery(PDO $pdo, array $types) {
    $where = ['1 = 1'];
    $params = [];
    $filters = [];

    $type = $_GET['type'] ?? '';
    if (is_string($type) && isset($types[$type])) {
        $where[] = 'e.Type = ?';
        $params[] = $types[$type];
        $filters['type'] = $type;
    }
    $decision = $_GET['decision'] ?? '';
    if ($decision === 'approved' || $decision === 'rejected') {
        $where[] = 'e.Decision = ?';
        $params[] = ucfirst($decision);
        $filters['decision'] = $decision;
    }
    $person = filter_var($_GET['person'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($person) {
        $where[] = 'e.DeciderId = ?';
        $params[] = (int) $person;
        $filters['person'] = (int) $person;
    }
    $range = $_GET['range'] ?? 'all';
    if ($range === '7' || $range === '30') {
        $where[] = 'e.At >= DATE_SUB(CURDATE(), INTERVAL ' . ((int) $range - 1) . ' DAY)';
        $filters['date'] = 'last ' . $range . ' days';
    } elseif ($range === 'season') {
        $current = kbfSeasonAt(kbfSeasonSettings($pdo)['months']);
        $season = kbfSeasonRange($current['key']);
        if ($season) {
            $where[] = 'e.At >= ? AND e.At < ?';
            $params[] = $season['from'] . ' 00:00:00';
            $params[] = $season['to'] . ' 00:00:00';
            $filters['date'] = 'this season';
        }
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
    $q = apSearchParam();
    if ($q !== '') {
        $like = '%' . kbfLikeEscape($q) . '%';
        $where[] = "(e.ItemName LIKE ? OR e.Comment LIKE ? OR e.DeciderLogged LIKE ? OR CONCAT(COALESCE(ud.FirstName, ''), ' ', COALESCE(ud.LastName, '')) LIKE ?)";
        array_push($params, $like, $like, $like, $like);
        $filters['search'] = $q;
    }

    $sql = "
        SELECT e.*, ud.FirstName AS DeciderFirst, ud.LastName AS DeciderLast,
               (CASE WHEN e.Type = 'Registration'
                     THEN (SELECT c.ShortName FROM PlayerRegistrations pr JOIN Categories c ON c.CategoryId = pr.CategoryId
                           WHERE pr.RegistrationId = e.RefId)
                END) AS ChosenGrade
        FROM (" . apHistoryUnionSql($types) . ") e
        LEFT JOIN Users ud ON ud.UserId = e.DeciderId
        WHERE " . implode(' AND ', $where);
    return [$sql, $params, $filters];
}

function apHistoryRow(array $r) {
    $decider = apName($r['DeciderFirst'], $r['DeciderLast']);
    if ($decider === '') {
        $decider = trim(apText($r['DeciderLogged'] ?? ''));
    }
    $grade = '';
    if ($r['Type'] === 'Registration' && $r['Decision'] === 'Approved') {
        if (preg_match('/Grade:\s*([^,;]+)/', (string) $r['Details'], $m)) {
            $grade = trim($m[1]);
        } else {
            $grade = (string) ($r['ChosenGrade'] ?? '');
        }
    }
    return [
        'when' => (string) $r['At'],
        'type' => AP_TYPE_LABELS[$r['Type']] ?? (string) $r['Type'],
        'item' => trim(apText($r['ItemName'] ?? '')) ?: '(removed)',
        'decision' => (string) $r['Decision'],
        'decidedBy' => $decider !== '' ? $decider : 'Unknown',
        'grade' => $grade,
        'reason' => trim(apText($r['Comment'] ?? '')),
    ];
}

// ============================================
// GET
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    apRequireLogin();
    $canRegistrations = hasPermission('players.approve_registrations');
    $canTournaments = hasPermission('tournaments.approve');
    if (!$canRegistrations && !$canTournaments) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
    $action = (string) $_GET['action'];

    try {
        // ---------- tab counts, permissions, grades, reasons ----------
        if ($action === 'meta') {
            $grades = [];
            foreach (kbfActiveGrades($pdo) as $g) {
                $grades[] = ['id' => (int) $g['CategoryId'], 'name' => apText($g['Name']), 'shortName' => apText($g['ShortName'])];
            }
            sendJSON([
                'success' => true,
                'counts' => apCounts($pdo),
                'can' => [
                    'registrations' => $canRegistrations,
                    'tournaments' => $canTournaments,
                    'changeGrades' => hasPermission('players.change_grades'),
                    'approveSelected' => $canRegistrations && hasPermission('players.change_grades'),
                ],
                'grades' => $grades,
                'reasons' => [
                    'registration' => apReasonsFor('registration'),
                    'tournament' => apReasonsFor('tournament'),
                ],
            ]);
        }

        // ---------- queues ----------
        if ($action === 'queue') {
            $type = $_GET['type'] ?? '';
            $q = apSearchParam();
            $sort = apSortParam();
            $offset = kbfOffsetParam();
            $like = '%' . kbfLikeEscape($q) . '%';

            if ($type === 'registrations') {
                apRequire('players.approve_registrations');
                $where = ["pr.Status = 'Pending'"];
                $params = [];
                if ($q !== '') {
                    $where[] = "(CONCAT(pr.FirstName, ' ', pr.LastName) LIKE ? OR pr.Location LIKE ?)";
                    array_push($params, $like, $like);
                }
                $grade = filter_var($_GET['grade'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($grade) {
                    $where[] = 'pr.CategoryId = ?';
                    $params[] = (int) $grade;
                }
                $order = $sort === 'newest' ? 'pr.SubmittedDate DESC, pr.RegistrationId DESC'
                    : ($sort === 'name' ? 'pr.FirstName ASC, pr.LastName ASC, pr.RegistrationId ASC' : 'pr.SubmittedDate ASC, pr.RegistrationId ASC');
                $whereSql = implode(' AND ', $where);

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM PlayerRegistrations pr WHERE $whereSql");
                $stmt->execute($params);
                $total = (int) $stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT pr.RegistrationId, pr.FirstName, pr.LastName, pr.Location, pr.ProfilePhoto, pr.SubmittedDate,
                           c.ShortName AS Grade, c.Name AS GradeName,
                           DATEDIFF(CURDATE(), DATE(pr.SubmittedDate)) AS Days,
                           " . apPossibleMemberSql() . " AS PossibleMember
                    FROM PlayerRegistrations pr
                    LEFT JOIN Categories c ON c.CategoryId = pr.CategoryId
                    WHERE $whereSql
                    ORDER BY $order
                    LIMIT " . APPROVAL_PAGE_SIZE . " OFFSET " . (int) $offset);
                $stmt->execute($params);
                $items = array_map(function ($r) {
                    return [
                        'id' => (int) $r['RegistrationId'],
                        'name' => apName($r['FirstName'], $r['LastName']),
                        'city' => apText($r['Location']),
                        'photo' => $r['ProfilePhoto'],
                        'grade' => apText($r['Grade']),
                        'submitted' => $r['SubmittedDate'],
                        'days' => max(0, (int) $r['Days']),
                        'possibleMember' => (int) $r['PossibleMember'] === 1,
                    ];
                }, $stmt->fetchAll());

                // Waiting-time counts over the whole queue
                $w = $pdo->query("
                    SELECT SUM(d < 7) AS under7, SUM(d BETWEEN 7 AND 30) AS mid, SUM(d > 30) AS over30
                    FROM (SELECT DATEDIFF(CURDATE(), DATE(SubmittedDate)) AS d FROM PlayerRegistrations WHERE Status = 'Pending') x
                ")->fetch();

                sendJSON([
                    'success' => true,
                    'items' => $items,
                    'total' => $total,
                    'offset' => $offset,
                    'pageSize' => APPROVAL_PAGE_SIZE,
                    'waiting' => [
                        'under7' => (int) ($w['under7'] ?? 0),
                        'from7to30' => (int) ($w['mid'] ?? 0),
                        'over30' => (int) ($w['over30'] ?? 0),
                    ],
                    'counts' => apCounts($pdo),
                ]);
            }

            if ($type === 'tournaments') {
                apRequire('tournaments.approve');
                $where = ["t.Status = 'Pending'"];
                $params = [];
                if ($q !== '') {
                    $where[] = "(t.Name LIKE ? OR " . organiserNameSql('t', 'c') . " LIKE ? OR t.Location LIKE ?)";
                    array_push($params, $like, $like, $like);
                }
                $order = $sort === 'newest' ? 't.CreatedDate DESC, t.TournamentId DESC'
                    : ($sort === 'name' ? 't.Name ASC, t.TournamentId ASC' : 't.CreatedDate ASC, t.TournamentId ASC');
                $whereSql = implode(' AND ', $where);

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM Tournaments t LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId WHERE $whereSql");
                $stmt->execute($params);
                $total = (int) $stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT t.TournamentId, t.Name, t.TournamentDate, t.Location, t.CreatedDate,
                           " . organiserNameSql('t', 'c') . " AS OrganiserName,
                           DATEDIFF(CURDATE(), DATE(t.CreatedDate)) AS Days
                    FROM Tournaments t
                    LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
                    WHERE $whereSql
                    ORDER BY $order
                    LIMIT " . APPROVAL_PAGE_SIZE . " OFFSET " . (int) $offset);
                $stmt->execute($params);
                $items = array_map(function ($r) {
                    return [
                        'id' => (int) $r['TournamentId'],
                        'name' => apText($r['Name']),
                        'date' => substr((string) $r['TournamentDate'], 0, 10),
                        'organiser' => apText($r['OrganiserName']),
                        'town' => apText($r['Location']),
                        'submitted' => $r['CreatedDate'],
                        'days' => max(0, (int) $r['Days']),
                    ];
                }, $stmt->fetchAll());

                sendJSON(['success' => true, 'items' => $items, 'total' => $total, 'offset' => $offset,
                    'pageSize' => APPROVAL_PAGE_SIZE, 'counts' => apCounts($pdo)]);
            }

            if ($type === 'results') {
                apRequire('tournaments.approve');
                $where = ["ra.Status = 'Pending'"];
                $params = [];
                if ($q !== '') {
                    $where[] = "(t.Name LIKE ? OR " . organiserNameSql('t', 'c') . " LIKE ? OR t.Location LIKE ?)";
                    array_push($params, $like, $like, $like);
                }
                $order = $sort === 'newest' ? 'ra.SubmittedDate DESC, ra.ApprovalId DESC'
                    : ($sort === 'name' ? 't.Name ASC, ra.ApprovalId ASC' : 'ra.SubmittedDate ASC, ra.ApprovalId ASC');
                $whereSql = implode(' AND ', $where);
                $from = "FROM ResultsApprovalQueue ra
                    JOIN Tournaments t ON ra.TournamentId = t.TournamentId
                    LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId";

                $stmt = $pdo->prepare("SELECT COUNT(*) $from WHERE $whereSql");
                $stmt->execute($params);
                $total = (int) $stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT ra.ApprovalId, ra.TournamentId, ra.SubmittedDate, t.Name, t.TournamentDate, t.Location,
                           " . organiserNameSql('t', 'c') . " AS OrganiserName,
                           DATEDIFF(CURDATE(), DATE(ra.SubmittedDate)) AS Days,
                           (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(m.GroupName), ''), 'No category name')
                                                ORDER BY m.GroupName SEPARATOR ', ')
                            FROM Matches m WHERE m.TournamentId = ra.TournamentId AND m.IsApproved = 0) AS Categories
                    $from
                    WHERE $whereSql
                    ORDER BY $order
                    LIMIT " . APPROVAL_PAGE_SIZE . " OFFSET " . (int) $offset);
                $stmt->execute($params);
                $items = array_map(function ($r) {
                    return [
                        'id' => (int) $r['ApprovalId'],
                        'tournamentId' => (int) $r['TournamentId'],
                        'name' => apText($r['Name']),
                        'date' => substr((string) $r['TournamentDate'], 0, 10),
                        'organiser' => apText($r['OrganiserName']),
                        'town' => apText($r['Location']),
                        'categories' => apText($r['Categories']),
                        'submitted' => $r['SubmittedDate'],
                        'days' => max(0, (int) $r['Days']),
                    ];
                }, $stmt->fetchAll());

                sendJSON(['success' => true, 'items' => $items, 'total' => $total, 'offset' => $offset,
                    'pageSize' => APPROVAL_PAGE_SIZE, 'counts' => apCounts($pdo)]);
            }

            sendJSON(['success' => false, 'message' => 'Unknown queue'], 400);
        }

        // ---------- one registration ----------
        if ($action === 'registration') {
            apRequire('players.approve_registrations');
            $id = apIdParam();
            $stmt = $pdo->prepare("
                SELECT pr.*, c.ShortName AS GradeShort, c.Name AS GradeName,
                       DATEDIFF(CURDATE(), DATE(pr.SubmittedDate)) AS Days
                FROM PlayerRegistrations pr
                LEFT JOIN Categories c ON c.CategoryId = pr.CategoryId
                WHERE pr.RegistrationId = ?
            ");
            $stmt->execute([$id]);
            $r = $stmt->fetch();
            if (!$r) {
                sendJSON(['success' => false, 'message' => 'Registration not found'], 404);
            }
            $waiting = $r['Status'] === 'Pending';
            // Contact details: shown while waiting so the details can be
            // checked; once decided, only with players.contact_details
            $showContact = $waiting || hasPermission('players.contact_details');
            sendJSON([
                'success' => true,
                'registration' => [
                    'id' => (int) $r['RegistrationId'],
                    'status' => (string) $r['Status'],
                    'firstName' => apText($r['FirstName']),
                    'lastName' => apText($r['LastName']),
                    'name' => apName($r['FirstName'], $r['LastName']),
                    'city' => apText($r['Location']),
                    'postCode' => apText($r['PostCode']),
                    'yearOfBirth' => $r['YearOfBirth'] !== null ? (string) $r['YearOfBirth'] : '',
                    'photo' => $r['ProfilePhoto'],
                    'gradeId' => $r['CategoryId'] !== null ? (int) $r['CategoryId'] : null,
                    'grade' => apText($r['GradeShort']),
                    'gradeName' => apText($r['GradeName']),
                    'kbfId' => (string) $r['PlayerId'],
                    'submitted' => $r['SubmittedDate'],
                    'days' => max(0, (int) $r['Days']),
                    'termsVersion' => isset($r['TermsVersion']) ? (string) $r['TermsVersion'] : '',
                    'termsDate' => $r['TermsAcceptedDate'] ?? null,
                    'email' => $showContact ? (string) $r['Email'] : null,
                    'phone' => $showContact ? apText($r['PhoneNumber']) : null,
                ],
                'matches' => apPossibleMembers($pdo, $id),
            ]);
        }

        // ---------- one tournament ----------
        if ($action === 'tournament') {
            apRequire('tournaments.approve');
            $id = apIdParam();
            $stmt = $pdo->prepare("
                SELECT t.*, " . organiserNameSql('t', 'c') . " AS OrganiserNameShown,
                       u.FirstName AS ByFirst, u.LastName AS ByLast,
                       DATEDIFF(CURDATE(), DATE(t.CreatedDate)) AS Days
                FROM Tournaments t
                LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
                LEFT JOIN Users u ON u.UserId = t.CreatedByAdminId
                WHERE t.TournamentId = ?
            ");
            $stmt->execute([$id]);
            $t = $stmt->fetch();
            if (!$t) {
                sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
            }
            sendJSON([
                'success' => true,
                'tournament' => [
                    'id' => (int) $t['TournamentId'],
                    'status' => (string) $t['Status'],
                    'name' => apText($t['Name']),
                    'date' => substr((string) $t['TournamentDate'], 0, 10),
                    'startTime' => $t['StartTime'] ?? null,
                    'venue' => apText($t['Venue']),
                    'town' => apText($t['Location']),
                    'postCode' => apText($t['PostCode'] ?? ''),
                    'categories' => apText($t['CategoriesOffered']),
                    'organiser' => apText($t['OrganiserNameShown']),
                    'poster' => $t['PosterPath'] ?? null,
                    'registrationUrl' => $t['RegistrationUrl'] ?? null,
                    'registrationCloseDate' => $t['RegistrationCloseDate'] ?? null,
                    'entryFee' => apText($t['EntryFee'] ?? ''),
                    'description' => apText($t['Description'] ?? ''),
                    'contactName' => apText($t['ContactName'] ?? ''),
                    'contactPhone' => apText($t['ContactPhone'] ?? ''),
                    'submittedBy' => apName($t['ByFirst'], $t['ByLast']),
                    'submitted' => $t['CreatedDate'],
                    'days' => max(0, (int) $t['Days']),
                ],
            ]);
        }

        // ---------- one set of results ----------
        if ($action === 'results') {
            apRequire('tournaments.approve');
            $id = apIdParam();
            $stmt = $pdo->prepare("
                SELECT ra.ApprovalId, ra.TournamentId, ra.Status, ra.SubmittedDate,
                       t.Name, t.TournamentDate, t.Venue, t.Location,
                       " . organiserNameSql('t', 'c') . " AS OrganiserName,
                       u.FirstName AS ByFirst, u.LastName AS ByLast,
                       DATEDIFF(CURDATE(), DATE(ra.SubmittedDate)) AS Days
                FROM ResultsApprovalQueue ra
                JOIN Tournaments t ON ra.TournamentId = t.TournamentId
                LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
                LEFT JOIN Users u ON u.UserId = ra.SubmittedByAdminId
                WHERE ra.ApprovalId = ?
            ");
            $stmt->execute([$id]);
            $r = $stmt->fetch();
            if (!$r) {
                sendJSON(['success' => false, 'message' => 'Results not found'], 404);
            }
            sendJSON([
                'success' => true,
                'results' => [
                    'id' => (int) $r['ApprovalId'],
                    'status' => (string) $r['Status'],
                    'tournamentId' => (int) $r['TournamentId'],
                    'name' => apText($r['Name']),
                    'date' => substr((string) $r['TournamentDate'], 0, 10),
                    'venue' => apText($r['Venue']),
                    'town' => apText($r['Location']),
                    'organiser' => apText($r['OrganiserName']),
                    'submittedBy' => apName($r['ByFirst'], $r['ByLast']),
                    'submitted' => $r['SubmittedDate'],
                    'days' => max(0, (int) $r['Days']),
                    'categories' => $r['Status'] === 'Pending' ? apResultsTeams($pdo, $r['TournamentId']) : [],
                ],
            ]);
        }

        // ---------- history ----------
        if ($action === 'history' || $action === 'historyCsv') {
            $types = apHistoryTypes();
            list($sql, $params, $filters) = apHistoryQuery($pdo, $types);
            $sql .= ' ORDER BY e.At DESC, e.EntryId DESC';

            if ($action === 'historyCsv') {
                $out = kbfCsvStart('approval-history', ['When', 'Type', 'Item', 'Decision', 'Decided by', 'Grade', 'Reason']);
                $rows = kbfStreamRows($pdo, $sql, $params, function ($r) use ($out) {
                    $h = apHistoryRow($r);
                    kbfCsvRow($out, [$h['when'], $h['type'], $h['item'], $h['decision'], $h['decidedBy'], $h['grade'], $h['reason']]);
                });
                fclose($out);
                $filterText = [];
                foreach ($filters as $k => $v) {
                    $filterText[] = $k . '=' . $v;
                }
                auditLog('report_downloaded', 'report', null,
                    'Report: Approval history; filters: ' . ($filterText ? implode(', ', $filterText) : 'none') . '; rows: ' . $rows);
                exit;
            }

            $offset = kbfOffsetParam();
            $stmt = $pdo->prepare($sql . ' LIMIT ' . (APPROVAL_PAGE_SIZE + 1) . ' OFFSET ' . $offset);
            $stmt->execute($params);
            $entries = array_map('apHistoryRow', $stmt->fetchAll());
            $hasMore = count($entries) > APPROVAL_PAGE_SIZE;
            if ($hasMore) {
                array_pop($entries);
            }

            list($plainSql, $plainParams) = apHistoryQuery($pdo, $types);
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($plainSql) z");
            $countStmt->execute($plainParams);
            $total = (int) $countStmt->fetchColumn();

            // People who have decided something, for the filter
            $people = [];
            $peopleStmt = $pdo->query("
                SELECT DISTINCT e.DeciderId, ud.FirstName, ud.LastName, e.DeciderLogged
                FROM (" . apHistoryUnionSql($types) . ") e
                LEFT JOIN Users ud ON ud.UserId = e.DeciderId
                WHERE e.DeciderId > 0
            ");
            foreach ($peopleStmt->fetchAll() as $p) {
                $pid = (int) $p['DeciderId'];
                if (isset($people[$pid])) {
                    continue;
                }
                $name = apName($p['FirstName'], $p['LastName']);
                $people[$pid] = ['id' => $pid, 'name' => $name !== '' ? $name : trim(apText($p['DeciderLogged'] ?? ''))];
            }
            $people = array_values(array_filter($people, function ($p) { return $p['name'] !== ''; }));
            usort($people, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

            $typeList = [];
            foreach ($types as $key => $logType) {
                $typeList[] = ['key' => $key, 'label' => AP_TYPE_LABELS[$logType]];
            }

            sendJSON([
                'success' => true,
                'entries' => $entries,
                'total' => $total,
                'offset' => $offset,
                'hasMore' => $hasMore,
                'people' => $people,
                'types' => $typeList,
            ]);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        error_log('Database error in approvals.php GET ' . $action . ': ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// GET (no action) - the earlier combined list, kept for older callers
// Registrations need players.approve_registrations; tournaments and results
// need tournaments.approve. Each person sees only what they can approve.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    apRequireLogin();
    $canApproveRegistrations = hasPermission('players.approve_registrations');
    $canApproveTournaments = hasPermission('tournaments.approve');
    if (!$canApproveRegistrations && !$canApproveTournaments) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }

    try {
        $pendingTournaments = [];
        $pendingResults = [];
        $pendingRegistrations = [];

        if ($canApproveTournaments) {
            $stmt = $pdo->query("
                SELECT t.TournamentId, t.Name, t.TournamentDate, t.Venue, t.Location, t.Status, t.CreatedDate,
                       t.PosterPath, t.RegistrationUrl,
                       " . organiserNameSql('t', 'c') . " as ClubName,
                       " . organiserNameSql('t', 'c') . " as OrganiserName,
                       u.FirstName as CreatedByFirstName, u.LastName as CreatedByLastName
                FROM Tournaments t
                LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
                LEFT JOIN Users u ON t.CreatedByAdminId = u.UserId
                WHERE t.Status = 'Pending'
                ORDER BY t.CreatedDate DESC
            ");
            $pendingTournaments = $stmt->fetchAll();

            $stmt = $pdo->query("
                SELECT ra.ApprovalId, ra.TournamentId, ra.Status, ra.SubmittedDate,
                       t.Name as TournamentName, t.TournamentDate,
                       " . organiserNameSql('t', 'c') . " as ClubName,
                       " . organiserNameSql('t', 'c') . " as OrganiserName,
                       u.FirstName as SubmittedByFirstName, u.LastName as SubmittedByLastName
                FROM ResultsApprovalQueue ra
                JOIN Tournaments t ON ra.TournamentId = t.TournamentId
                LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
                LEFT JOIN Users u ON ra.SubmittedByAdminId = u.UserId
                WHERE ra.Status = 'Pending'
                ORDER BY ra.SubmittedDate DESC
            ");
            $pendingResults = $stmt->fetchAll();
        }

        if ($canApproveRegistrations) {
            $stmt = $pdo->query("
                SELECT pr.RegistrationId, pr.PlayerId, pr.FirstName, pr.LastName,
                       pr.Location, pr.ProfilePhoto, pr.Status, pr.SubmittedDate,
                       c.Name as CategoryName
                FROM PlayerRegistrations pr
                JOIN Categories c ON pr.CategoryId = c.CategoryId
                WHERE pr.Status = 'Pending'
                ORDER BY pr.SubmittedDate DESC
            ");
            $pendingRegistrations = $stmt->fetchAll();
        }

        sendJSON([
            'success' => true,
            'pendingTournaments' => $pendingTournaments,
            'pendingResults' => $pendingResults,
            'pendingRegistrations' => $pendingRegistrations,
        ]);
    } catch (PDOException $e) {
        error_log('Database error in approvals.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST - Approve or reject one item, or approve selected registrations
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
    }

    // ---------- approve selected registrations ----------
    if (($data['action'] ?? '') === 'approveSelected') {
        if (!hasPermission('players.approve_registrations') || !hasPermission('players.change_grades')) {
            sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
        }
        $ids = $data['ids'] ?? null;
        if (!is_array($ids) || !$ids) {
            sendJSON(['success' => false, 'message' => 'Choose the registrations to approve'], 400);
        }
        $clean = [];
        foreach ($ids as $raw) {
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                sendJSON(['success' => false, 'message' => 'Invalid ID'], 400);
            }
            $clean[(int) $id] = true;
        }
        $clean = array_keys($clean);
        if (count($clean) > 50) {
            sendJSON(['success' => false, 'message' => 'Approve up to 50 registrations at a time'], 400);
        }

        $approved = 0;
        $skipped = [];
        $failed = [];
        $nameStmt = $pdo->prepare("SELECT FirstName, LastName, Status FROM PlayerRegistrations WHERE RegistrationId = ?");
        foreach ($clean as $id) {
            try {
                $nameStmt->execute([$id]);
                $reg = $nameStmt->fetch();
            } catch (PDOException $e) {
                $reg = false;
            }
            if (!$reg) {
                $failed[] = ['id' => $id, 'name' => 'Registration ' . $id, 'message' => 'Not found'];
                continue;
            }
            $name = apName($reg['FirstName'], $reg['LastName']);
            if ($reg['Status'] !== 'Pending') {
                $skipped[] = ['id' => $id, 'name' => $name, 'reason' => 'already decided'];
                continue;
            }
            try {
                $hasMatch = (bool) apPossibleMembers($pdo, $id);
            } catch (PDOException $e) {
                $hasMatch = true;
            }
            if ($hasMatch) {
                $skipped[] = ['id' => $id, 'name' => $name, 'reason' => 'needs individual review'];
                continue;
            }
            // At the grade the applicant chose; email and logs as usual
            $result = apDecide($pdo, 'registration', $id, 'approve', null, null, null);
            if ($result['success']) {
                $approved++;
            } else {
                $failed[] = ['id' => $id, 'name' => $name, 'message' => $result['message']];
            }
        }

        $message = $approved . ' registration' . ($approved === 1 ? '' : 's') . ' approved.';
        if ($skipped) {
            $message .= ' ' . count($skipped) . ' skipped.';
        }
        if ($failed) {
            $message .= ' ' . count($failed) . ' could not be approved.';
        }
        sendJSON([
            'success' => true,
            'message' => $message,
            'approved' => $approved,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    // ---------- one item ----------
    if (!isset($data['type']) || !isset($data['id']) || !isset($data['action'])) {
        sendJSON(['success' => false, 'message' => 'Type, ID, and action required'], 400);
    }

    $type = $data['type'];
    $id = filter_var($data['id'], FILTER_VALIDATE_INT);
    $action = $data['action'];

    if (!$id) {
        sendJSON(['success' => false, 'message' => 'Invalid ID'], 400);
    }
    if (!in_array($type, ['registration', 'tournament', 'results'], true)) {
        sendJSON(['success' => false, 'message' => 'Invalid type'], 400);
    }

    // Registrations: players.approve_registrations. Tournaments and results: tournaments.approve
    $neededPermission = ($type === 'registration') ? 'players.approve_registrations' : 'tournaments.approve';
    if (!hasPermission($neededPermission)) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        sendJSON(['success' => false, 'message' => 'Invalid action'], 400);
    }

    // Rejection: reasonKey and message (the message fills {reason});
    // older callers send comments
    $reasonKey = is_string($data['reasonKey'] ?? null) ? $data['reasonKey'] : null;
    if (is_string($data['message'] ?? null)) {
        $message = $data['message'];
    } elseif (is_string($data['comments'] ?? null)) {
        $message = $data['comments'];
    } else {
        $message = null;
    }
    if ($message !== null && (function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message)) > 1000) {
        sendJSON(['success' => false, 'message' => 'The message must be 1,000 characters or fewer'], 400);
    }

    $result = apDecide($pdo, $type, (int) $id, $action, $data['categoryId'] ?? null, $reasonKey, $message);
    if (!$result['success']) {
        sendJSON(['success' => false, 'message' => $result['message']], $result['code']);
    }
    sendJSON([
        'success' => true,
        'message' => $result['message'],
        'emailStatus' => $result['emailStatus']
    ]);
}
?>
