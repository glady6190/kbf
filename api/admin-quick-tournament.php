<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Invalid request method'], 405);
}

// Login (401), then CSRF, then permission (403)
if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCSRFToken($token)) {
    sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
}

requirePermission('tournaments.manage_all');

$json = file_get_contents('php://input');
$data = json_decode($json, true);
if (!is_array($data)) {
    sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
}
kbfRequireScalarFields($data);

// Validate required fields
$required = ['organizerName', 'tournamentName', 'venue', 'tournamentDate', 'location', 'categoriesOffered'];
foreach ($required as $field) {
    if (!isset($data[$field]) || empty(trim($data[$field]))) {
        sendJSON(['success' => false, 'message' => ucfirst($field) . ' is required'], 400);
    }
}

// Sanitize inputs
list($organizerName, $organiserError) = cleanOrganiserName($data['organizerName']);
if ($organiserError !== null) {
    sendJSON(['success' => false, 'message' => $organiserError], 400);
}
// Tournament names never hold < or > (defence in depth against markup)
$tournamentName = trim(str_replace(['<', '>'], '', (string) $data['tournamentName']));
$venue = trim($data['venue']);
$location = trim($data['location']);
$postCode = isset($data['postCode']) ? trim($data['postCode']) : null;
$categoriesOffered = trim($data['categoriesOffered']);
$tournamentDate = $data['tournamentDate'];

// Validate tournament name length
if (kbfTextLength($tournamentName) < 3 || kbfTextLength($tournamentName) > 200) {
    sendJSON(['success' => false, 'message' => 'Tournament name must be between 3 and 200 characters'], 400);
}

// Validate date: a real date, today or earlier (this page is for past tournaments)
if (!is_string($tournamentDate) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tournamentDate, $dateParts)
    || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])) {
    sendJSON(['success' => false, 'message' => 'Invalid date format'], 400);
}
if ($tournamentDate > date('Y-m-d')) {
    sendJSON(['success' => false, 'message' => 'The date must be today or earlier. Use Create tournament on the Tournaments page for future tournaments.'], 400);
}

// Validate categories length
if (strlen($categoriesOffered) > 100) {
    sendJSON(['success' => false, 'message' => 'Categories text too long (max 100 characters)'], 400);
}

// Duplicate check: similar tournaments within 3 days, unless the admin has
// chosen "Create a new tournament anyway"
require_once __DIR__ . '/tournament-helpers.php';
if (($data['createAnyway'] ?? false) !== true) {
    try {
        $similar = kbfSimilarTournaments($pdo, $tournamentName, $tournamentDate, (int) $_SESSION['user_id'], true);
    } catch (PDOException $e) {
        error_log('Quick tournament duplicate check error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
    if ($similar) {
        sendJSON([
            'success' => false,
            'duplicates' => $similar,
            'message' => 'There ' . (count($similar) === 1 ? 'is a tournament' : 'are tournaments') . ' with a similar name on or near this date.'
        ], 409);
    }
}

// A repeat of the same one-time request id (double tap, retry) gets the
// first answer back; no second tournament is created
require_once __DIR__ . '/request-ids.php';
kbfRequestIdBegin($pdo, 'quick_tournament', is_string($data['requestId'] ?? null) ? $data['requestId'] : null);

try {
    $pdo->beginTransaction();

    // The organiser is free text (OrganiserName); no club is created or linked.
    // Created as Approved: this endpoint is for tournaments.manage_all only.
    $stmt = $pdo->prepare("
        INSERT INTO Tournaments
        (Name, TournamentDate, Venue, Location, PostCode, CategoriesOffered,
         OrganizingClubId, OrganiserName, Status, CreatedByAdminId, CreatedByMainAdminDirectly,
         ApprovedByAdminId, ApprovedDate)
        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, 'Approved', ?, 1, ?, NOW())
    ");

    $stmt->execute([
        $tournamentName,
        $tournamentDate,
        $venue,
        $location,
        $postCode,
        $categoriesOffered,
        $organizerName,
        $_SESSION['user_id'], // Created by
        $_SESSION['user_id']  // Also approved by (same person)
    ]);

    $tournamentId = $pdo->lastInsertId();

    $pdo->commit();

    auditLog('tournament_created', 'tournament', $tournamentId, 'Quick tournament (Approved): ' . $tournamentName . ', organiser: ' . $organizerName);
    
    sendJSON([
        'success' => true,
        'message' => 'Tournament created successfully',
        'tournamentId' => $tournamentId,
        'redirectUrl' => "/admin/submit-tournament-results.html?id={$tournamentId}"
    ]);
    
} catch(PDOException $e) {
    $pdo->rollBack();
    error_log('Quick tournament creation error: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
}
?>