<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';

// Read only. The old write actions (POST add player, PUT edit details and
// grade, DELETE deactivate) are removed: they changed grades and points
// outside the shared rules. Adding, editing and deactivating players goes
// through api/people.php; grade changes through api/rankings.php.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    sendJSON(['success' => false, 'message' => 'Method not allowed. Use the People page to add or change players.'], 405);
}

// GET - Fetch users
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    try {
        if (isset($_GET['logs'])) {
    // Get user activity logs - Super Admin only
    requireSuperAdmin();

    try {
        $stmt = $pdo->query("
            SELECT LogId, ActionType, TargetUserId, TargetUserName, TargetUserDetails,
                   ChangedByAdminName, AdminLocation, LogDate, Comments
            FROM UserActivityLogs
            ORDER BY LogDate DESC
            LIMIT 100
        ");
        $logs = $stmt->fetchAll();

        sendJSON([
            'success' => true,
            'logs' => $logs
        ]);
    } catch(PDOException $e) {
        error_log('Database error in users.php GET logs: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
        if (isset($_GET['id'])) {
            // Single user profile - PUBLIC ACCESS for player profiles
            // Validate and sanitize ID
            $userId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            if (!$userId) {
                sendJSON(['success' => false, 'message' => 'Invalid user ID'], 400);
            }

            // Public player profile (api/player-profile-data.php): no year of
            // birth, no under-18 photo, no grade-change reasons or admin names
            require_once __DIR__ . '/player-profile-data.php';
            kbfSendPublicProfile($pdo, $userId);

        } else {
            // Get all users list

            $role = isset($_GET['role']) && !empty($_GET['role']) ? $_GET['role'] : null;
$category = isset($_GET['category']) && !empty($_GET['category']) ? $_GET['category'] : null;
$search = isset($_GET['search']) && !empty($_GET['search']) ? $_GET['search'] : null;



// Who is asking
$isAuthenticated = isLoggedIn();
$canViewFullList = $isAuthenticated && hasPermission('players.view_export');
$canSeeContacts = $isAuthenticated && hasPermission('players.contact_details');

// Public rankings request: filtered by category, or the player list, no search
$isPublicFilter = ($role === 'Player' && $search === null) || ($category !== null && $search === null);

// The full list needs players.view_export. Other staff (e.g. organisers
// picking players for results) get the public fields only.
if (!$canViewFullList) {
    if (!$isAuthenticated && !$isPublicFilter) {
        sendJSON(['success' => false, 'message' => 'Authentication required'], 401);
    }
    if ($isAuthenticated && !$isPublicFilter && !isStaff()) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
}
$isPublicRankingsRequest = !$canViewFullList;

// Staff-facing lists mark each person as staff (Super Admin or at least one
// permission) so pages can hide Edit/Deactivate for non-Super Admins.
// Never sent to public visitors.
$staffFlagColumn = ($isAuthenticated && isStaff())
    ? ", (CASE WHEN u.IsSuperAdmin = 1 OR EXISTS (SELECT 1 FROM UserPermissions sfp WHERE sfp.UserId = u.UserId) THEN 1 ELSE 0 END) AS IsStaff"
    : "";

if ($role !== null && !in_array($role, ['Player', 'ClubAdmin', 'MainAdmin', 'ZonalAdmin'])) {
    sendJSON(['success' => false, 'message' => 'Invalid role'], 400);
}

// Validate category
if ($category && !categoryShortNameExists($pdo, $category)) {
    sendJSON(['success' => false, 'message' => 'Invalid category'], 400);
}

// Build query
// Build query
if ($isPublicRankingsRequest) {
    // PUBLIC ACCESS: Limited fields, all active users (admins are also players)
    $query = "
        SELECT u.UserId, u.PlayerId, u.FirstName, u.LastName, u.Location,
               u.TotalPoints, u.CategoryId, u.ProfilePhoto, u.YearOfBirth,
               c.Name as CategoryName, cl.Name as ClubName,
               pt.PointsInCurrentCategory, pt.CareerPoints, pt.FinalsInCurrentCategory" . $staffFlagColumn . "
        FROM Users u
        LEFT JOIN Categories c ON u.CategoryId = c.CategoryId
        LEFT JOIN Clubs cl ON u.ClubId = cl.ClubId
        LEFT JOIN PlayerPerformanceTracker pt ON u.UserId = pt.PlayerId
        WHERE u.IsActive = 1 AND u.IsRemoved = 0
    ";
} else {
    // FULL LIST (players.view_export). Contact details only with players.contact_details.
    $contactColumns = $canSeeContacts ? "u.Email, u.PostCode, u.PhoneNumber, u.YearOfBirth," : "";
    $query = "
        SELECT u.UserId, u.PlayerId, u.FirstName, u.LastName, u.Location, " . $contactColumns . "
               u.ProfilePhoto, u.TotalPoints, u.Role, u.CategoryId,
               u.TermsVersion, u.TermsAcceptedDate,
               c.Name as CategoryName, cl.Name as ClubName, cl.ClubId,
               pt.PointsInCurrentCategory, pt.CareerPoints, pt.FinalsInCurrentCategory" . $staffFlagColumn . "
        FROM Users u
        LEFT JOIN Categories c ON u.CategoryId = c.CategoryId
        LEFT JOIN Clubs cl ON u.ClubId = cl.ClubId
        LEFT JOIN PlayerPerformanceTracker pt ON u.UserId = pt.PlayerId
        WHERE u.IsActive = 1 AND u.IsRemoved = 0
    ";
}

$params = [];

// Apply role filter if provided
if ($role) {
    $query .= " AND u.Role = ?";
    $params[] = $role;
}


if ($category) {
    $query .= " AND c.ShortName = ?";
    $params[] = $category;
}

            if ($search) {
                // SECURITY: Use parameterized query for LIKE search.
                // Email is searchable only for people who may see emails.
                $searchTerm = '%' . $search . '%';
                if ($canSeeContacts) {
                    $query .= " AND (u.FirstName LIKE ? OR u.LastName LIKE ? OR u.Email LIKE ? OR u.PlayerId LIKE ?)";
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                } else {
                    $query .= " AND (u.FirstName LIKE ? OR u.LastName LIKE ? OR u.PlayerId LIKE ?)";
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                }
            }

            $query .= " ORDER BY c.DisplayOrder, u.TotalPoints DESC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            if ($isPublicRankingsRequest) {
                // Public grading lists: no under-18 photos and no year of
                // birth for visitors who are not staff
                $users = kbfPublicPlayerRows($users);
            }

            sendJSON([
                'success' => true,
                'users' => $users
            ]);
        }
    } catch(PDOException $e) {
    error_log('Database error in users.php GET: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
}
}
?>
