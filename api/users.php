<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';

// Writes: login (401), then CSRF (403); permissions are checked per action
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
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

// POST - Create a new player (players.approve_registrations AND players.edit_details).
// This endpoint never creates staff or sets roles, passwords or clubs:
// staff are added only through api/staff.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hasPermission('players.approve_registrations') || !hasPermission('players.edit_details')) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
    }

    if (isset($input['role']) && $input['role'] !== '' && $input['role'] !== 'Player') {
        sendJSON(['success' => false, 'message' => 'Staff are added on the Staff page'], 400);
    }

    // Validate required fields
    $requiredFields = ['email', 'firstName', 'lastName', 'location', 'postCode'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            sendJSON(['success' => false, 'message' => "Field '$field' is required"], 400);
        }
    }
    
    // Validate and sanitize inputs
    $email = filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        sendJSON(['success' => false, 'message' => 'Invalid email format'], 400);
    }
    
    $firstName = sanitizeInput($input['firstName']);
    $lastName = sanitizeInput($input['lastName']);
    $location = sanitizeInput($input['location']);
    $postCode = sanitizeInput($input['postCode']);
    $role = 'Player'; // stored for display only; never a staff role here
    
    // Validate optional fields
    $phoneNumber = isset($input['phoneNumber']) ? sanitizeInput($input['phoneNumber']) : null;
    $yearOfBirth = null;
    if (isset($input['yearOfBirth']) && !empty($input['yearOfBirth'])) {
        $yearOfBirth = filter_var($input['yearOfBirth'], FILTER_VALIDATE_INT);
        // Youngest allowed age from Site settings (min_player_age)
        if (!$yearOfBirth || $yearOfBirth < 1940 || $yearOfBirth > kbfLatestBirthYear()) {
            sendJSON(['success' => false, 'message' => 'Invalid year of birth'], 400);
        }
    }

    $clubId = null; // clubs are no longer set here

    // Default grade from Site settings (default_grade_id), or the
    // lowest-ranked active grade when that grade is missing or retired
    $categoryId = kbfDefaultGradeId($pdo);
    if (isset($input['categoryId'])) {
        $categoryId = filter_var($input['categoryId'], FILTER_VALIDATE_INT);
        if (!$categoryId || !categoryExists($pdo, $categoryId)) {
            sendJSON(['success' => false, 'message' => 'Invalid category ID'], 400);
        }
    }
    
    // Validate profile photo path if provided
    $profilePhoto = null;
    if (isset($input['profilePhoto']) && !empty($input['profilePhoto'])) {
        $profilePhoto = $input['profilePhoto'];
        // SECURITY: Validate photo path to prevent path traversal
        if (!preg_match('#^/uploads/(profiles|registrations)/[a-zA-Z0-9_]+\.(jpg|jpeg|png|webp)$#', $profilePhoto)) {
            sendJSON(['success' => false, 'message' => 'Invalid photo path'], 400);
        }
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT UserId FROM Users WHERE Email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Email already exists'], 400);
        }
        
        // Generate a Player ID for anyone who has a grade (being a player follows
        // the grade, not the role). A brand-new user never already has one.
$playerId = null;
$hasGrade = !empty($categoryId);
if ($hasGrade) {
    // Lock BOTH tables to prevent race conditions
    $pdo->query("SELECT UserId FROM Users ORDER BY UserId DESC LIMIT 1 FOR UPDATE");
    $pdo->query("SELECT RegistrationId FROM PlayerRegistrations ORDER BY RegistrationId DESC LIMIT 1 FOR UPDATE");
    
    // Get highest Player ID from Users table
    $stmt = $pdo->query("
        SELECT PlayerId FROM Users 
        WHERE PlayerId IS NOT NULL 
        ORDER BY CAST(SUBSTRING(PlayerId, 6) AS UNSIGNED) DESC 
        LIMIT 1
    ");
    $lastUserPlayerId = $stmt->fetch();
    
    // Get highest Player ID from PlayerRegistrations table
    $stmt = $pdo->query("
        SELECT PlayerId FROM PlayerRegistrations 
        WHERE PlayerId IS NOT NULL
        ORDER BY CAST(SUBSTRING(PlayerId, 6) AS UNSIGNED) DESC 
        LIMIT 1
    ");
    $lastRegPlayerId = $stmt->fetch();
    
    // Find the highest number from BOTH tables
    $lastNumber = 0;
    if ($lastUserPlayerId && $lastUserPlayerId['PlayerId']) {
        $lastNumber = max($lastNumber, (int)substr($lastUserPlayerId['PlayerId'], 5)); // KBFUK = 5 chars
    }
    if ($lastRegPlayerId && $lastRegPlayerId['PlayerId']) {
        $lastNumber = max($lastNumber, (int)substr($lastRegPlayerId['PlayerId'], 5)); // KBFUK = 5 chars
    }
    
    // Generate new Player ID with KBFUK format (consistent with registration)
    $newNumber = $lastNumber + 1;
    $playerId = 'KBFUK' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
}
        
        // Players never have a password; sign-in is for staff (api/staff.php)
        $passwordHash = null;
        
        // Insert user
        $stmt = $pdo->prepare("
            INSERT INTO Users 
            (PlayerId, Email, PasswordHash, FirstName, LastName, Location, PostCode, PhoneNumber, 
             YearOfBirth, ProfilePhoto, Role, ClubId, CategoryId, TotalPoints, IsActive, CreatedDate) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW())
        ");
        
        $stmt->execute([
            $playerId,
            $email,
            $passwordHash,
            $firstName,
            $lastName,
            $location,
            $postCode,
            $phoneNumber,
            $yearOfBirth,
            $profilePhoto,
            $role,
            $clubId,
            $categoryId
        ]);
        
        $userId = $pdo->lastInsertId();
        
        // Log user creation
$adminLocation = '';
$stmt = $pdo->prepare("SELECT Location FROM Users WHERE UserId = ?");
$stmt->execute([$_SESSION['user_id']]);
$adminLoc = $stmt->fetch();
if ($adminLoc) {
    $adminLocation = $adminLoc['Location'];
}

$logDetails = "Name: {$firstName} {$lastName}, Email: {$email}, Location: {$location}";
$stmt = $pdo->prepare("
    INSERT INTO UserActivityLogs 
    (ActionType, TargetUserId, TargetUserName, TargetUserDetails, ChangedByAdminId, ChangedByAdminName, AdminLocation, Comments)
    VALUES ('Created', ?, ?, ?, ?, ?, ?, 'New user added to system')
");
$stmt->execute([
    $userId,
    $firstName . ' ' . $lastName,
    $logDetails,
    $_SESSION['user_id'],
    $_SESSION['name'],
    $adminLocation
]);
        
        // Create a PlayerPerformanceTracker entry for anyone who has a grade,
        // unless one already exists
        if ($hasGrade) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM PlayerPerformanceTracker WHERE PlayerId = ?");
            $stmt->execute([$userId]);
            $hasTracker = (int) $stmt->fetch()['cnt'] > 0;
        }
        if ($hasGrade && !$hasTracker) {
            $stmt = $pdo->prepare("
                INSERT INTO PlayerPerformanceTracker 
                (PlayerId, CategoryId, FinalsReached, SemiFinalsReached, GroupStageFailures, 
                 TotalTournamentsPlayed, Last6MonthsPoints, CareerPoints, 
                 EligibleForPromotion, FlaggedForDemotion, LastCalculatedDate)
                VALUES (?, ?, 0, 0, 0, 0, 0, 0, 0, 0, NOW())
            ");
            $stmt->execute([$userId, $categoryId]);
        }
        
        // Commit transaction
        $pdo->commit();

        auditLog('player_created', 'user', $userId, 'Player: ' . $firstName . ' ' . $lastName);

        // player_added: sends the KBF ID to the new player (after the commit;
        // a problem never undoes the new player)
        $message = 'User created successfully';
        if ($playerId !== null) {
            require_once __DIR__ . '/notify.php';
            $gradeStmt = $pdo->prepare("SELECT Name FROM Categories WHERE CategoryId = ?");
            $gradeStmt->execute([$categoryId]);
            $results = notify('player_added', [
                'person' => (int) $userId,
                'values' => [
                    'first_name' => html_entity_decode((string) $firstName, ENT_QUOTES, 'UTF-8'),
                    'full_name' => html_entity_decode(trim($firstName . ' ' . $lastName), ENT_QUOTES, 'UTF-8'),
                    'kbf_id' => $playerId,
                    'grade' => (string) $gradeStmt->fetchColumn(),
                ],
                'relatedType' => 'user',
                'relatedId' => (int) $userId,
            ]);
            if (in_array($results['person'] ?? '', ['sent', 'waiting', 'failed'], true)) {
                $message .= '. ' . kbfNotifyOutcomeText($results, 'person', 'The welcome email');
            }
        }

        sendJSON([
            'success' => true,
            'message' => $message,
            'userId' => $userId,
            'playerId' => $playerId
        ]);
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        error_log('Database error in users.php POST: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// PUT - Update user
// players.edit_details: name, location, postcode, phone, year of birth, photo.
// players.change_grades: the grade. Role and club can no longer be changed.
// A staff member's details can only be edited by a Super Admin.
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!isset($data['userId'])) {
        sendJSON(['success' => false, 'message' => 'User ID required'], 400);
    }
    
    $userId = filter_var($data['userId'], FILTER_VALIDATE_INT);
    if (!$userId) {
        sendJSON(['success' => false, 'message' => 'Invalid user ID'], 400);
    }
    
    if (!is_array($data)) {
        sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
    }

    if (array_key_exists('role', $data) || array_key_exists('clubId', $data)
        || array_key_exists('temporaryPassword', $data) || array_key_exists('requirePasswordChange', $data)) {
        sendJSON(['success' => false, 'message' => 'Roles, clubs and passwords can no longer be changed here. Use the Staff page.'], 400);
    }

    $detailFields = ['firstName', 'lastName', 'location', 'postCode', 'phoneNumber', 'yearOfBirth', 'profilePhoto'];
    $changesDetails = false;
    foreach ($detailFields as $field) {
        if (isset($data[$field])) {
            $changesDetails = true;
        }
    }
    $changesGrade = isset($data['categoryId']);

    if ($changesDetails && !hasPermission('players.edit_details')) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
    if ($changesGrade && !hasPermission('players.change_grades')) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
    if (!$changesDetails && !$changesGrade) {
        sendJSON(['success' => false, 'message' => 'No valid fields to update'], 400);
    }

    // Staff members can only be edited by a Super Admin (read from the database)
    if (userIsStaff($userId) && !isSuperAdmin()) {
        sendJSON(['success' => false, 'message' => 'Only a Super Admin can edit a staff member'], 403);
    }

    try {
        $updates = [];
        $params = [];
        $oldCategoryId = null;
        $newCategoryId = null;
                
        if (isset($data['firstName'])) {
            $updates[] = "FirstName = ?";
            $params[] = sanitizeInput($data['firstName']);
        }
        if (isset($data['lastName'])) {
            $updates[] = "LastName = ?";
            $params[] = sanitizeInput($data['lastName']);
        }
        if (isset($data['location'])) {
            $updates[] = "Location = ?";
            $params[] = sanitizeInput($data['location']);
        }
        if (isset($data['postCode'])) {
            $updates[] = "PostCode = ?";
            $params[] = sanitizeInput($data['postCode']);
        }
        if (isset($data['phoneNumber'])) {
            $updates[] = "PhoneNumber = ?";
            $params[] = sanitizeInput($data['phoneNumber']);
        }
        if (isset($data['yearOfBirth'])) {
            $yearOfBirth = filter_var($data['yearOfBirth'], FILTER_VALIDATE_INT);
            if ($yearOfBirth && $yearOfBirth >= 1940 && $yearOfBirth <= kbfLatestBirthYear()) {
                $updates[] = "YearOfBirth = ?";
                $params[] = $yearOfBirth;
            }
        }
        if (isset($data['profilePhoto'])) {
            // SECURITY: Validate photo path
            if (preg_match('#^/uploads/(profiles|registrations)/[a-zA-Z0-9_]+\.(jpg|jpeg|png|webp)$#', $data['profilePhoto'])) {
                $updates[] = "ProfilePhoto = ?";
                $params[] = $data['profilePhoto'];
            }
        }
        
        // Grade change (players.change_grades, checked above)
        if ($changesGrade) {
            $categoryId = filter_var($data['categoryId'], FILTER_VALIDATE_INT);
            if ($categoryId && categoryExists($pdo, $categoryId)) {
                $stmt = $pdo->prepare("SELECT CategoryId FROM Users WHERE UserId = ?");
                $stmt->execute([$userId]);
                $current = $stmt->fetch();
                $oldCategoryId = $current ? $current['CategoryId'] : null;
                if ((int) $oldCategoryId !== (int) $categoryId) {
                    $newCategoryId = $categoryId;
                    $updates[] = "CategoryId = ?";
                    $params[] = $categoryId;
                }
            }
        }

        if (empty($updates)) {
            sendJSON(['success' => false, 'message' => 'No valid fields to update'], 400);
        }
        
        $params[] = $userId;
        $query = "UPDATE Users SET " . implode(", ", $updates) . " WHERE UserId = ? AND IsActive = 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() === 0) {
    sendJSON(['success' => false, 'message' => 'User not found or no changes made'], 404);
}

// Log user update
$stmt = $pdo->prepare("SELECT FirstName, LastName, Location FROM Users WHERE UserId = ?");
$stmt->execute([$userId]);
$targetUser = $stmt->fetch();

$stmt = $pdo->prepare("SELECT Location FROM Users WHERE UserId = ?");
$stmt->execute([$_SESSION['user_id']]);
$adminLoc = $stmt->fetch();
$adminLocation = $adminLoc ? $adminLoc['Location'] : '';

// Build human-readable change details
$changedFields = [];
$updateStatements = $updates; // The array like ["FirstName = ?", "LastName = ?"]

foreach ($updateStatements as $statement) {
    // Extract field name from "FieldName = ?" format
    $fieldName = trim(explode('=', $statement)[0]);
    
    // Convert to human-readable format
    $readable = str_replace('Id', '', $fieldName); // Remove "Id" suffix
    $readable = preg_replace('/(?<!^)([A-Z])/', ' $1', $readable); // Add spaces before capitals
    $changedFields[] = ucfirst($readable); // Capitalize first letter
}

// ADD USER NAME AND DETAILS TO THE LOG
$userName = $targetUser['FirstName'] . ' ' . $targetUser['LastName'];
$changeDetails = "User: {$userName} | Updated: " . implode(", ", $changedFields);

$stmt = $pdo->prepare("
    INSERT INTO UserActivityLogs 
    (ActionType, TargetUserId, TargetUserName, TargetUserDetails, ChangedByAdminId, ChangedByAdminName, AdminLocation, Comments)
    VALUES ('Updated', ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $userId,
    $targetUser['FirstName'] . ' ' . $targetUser['LastName'],
    $changeDetails,
    $_SESSION['user_id'],
    $_SESSION['name'],
    $adminLocation,
    null
]);

if ($newCategoryId !== null) {
    auditLog('grade_changed', 'user', $userId,
        'Grade changed from category ' . ($oldCategoryId === null ? 'none' : $oldCategoryId) . ' to ' . $newCategoryId);
}

sendJSON([
    'success' => true,
    'message' => 'User updated successfully'
]);
        
    } catch(PDOException $e) {
        error_log('Database error in users.php PUT: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// DELETE - Deactivate user
// A player: players.edit_details AND players.change_grades.
// A staff member: Super Admin only. Nobody can deactivate themselves.
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!isset($_GET['id'])) {
        sendJSON(['success' => false, 'message' => 'User ID required'], 400);
    }

    $userId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if (!$userId) {
        sendJSON(['success' => false, 'message' => 'Invalid user ID'], 400);
    }

    if ((int) $userId === (int) $_SESSION['user_id']) {
        sendJSON(['success' => false, 'message' => 'You cannot deactivate your own account'], 403);
    }

    $targetIsStaff = userIsStaff($userId);
    if ($targetIsStaff) {
        if (!isSuperAdmin()) {
            sendJSON(['success' => false, 'message' => 'Only a Super Admin can deactivate a staff member'], 403);
        }
    } elseif (!hasPermission('players.edit_details') || !hasPermission('players.change_grades')) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }

    try {
        $stmt = $pdo->prepare("UPDATE Users SET IsActive = 0 WHERE UserId = ?");
        $stmt->execute([$userId]);
        
        if ($stmt->rowCount() === 0) {
    sendJSON(['success' => false, 'message' => 'User not found'], 404);
}

// Log user deactivation
$stmt = $pdo->prepare("SELECT FirstName, LastName, Email FROM Users WHERE UserId = ?");
$stmt->execute([$userId]);
$targetUser = $stmt->fetch();

$stmt = $pdo->prepare("SELECT Location FROM Users WHERE UserId = ?");
$stmt->execute([$_SESSION['user_id']]);
$adminLoc = $stmt->fetch();
$adminLocation = $adminLoc ? $adminLoc['Location'] : '';

$logDetails = "User: {$targetUser['FirstName']} {$targetUser['LastName']}, Email: {$targetUser['Email']}";

$stmt = $pdo->prepare("
    INSERT INTO UserActivityLogs 
    (ActionType, TargetUserId, TargetUserName, TargetUserDetails, ChangedByAdminId, ChangedByAdminName, AdminLocation, Comments)
    VALUES ('Deactivated', ?, ?, ?, ?, ?, ?, 'User account deactivated')
");
$stmt->execute([
    $userId,
    $targetUser['FirstName'] . ' ' . $targetUser['LastName'],
    $logDetails,
    $_SESSION['user_id'],
    $_SESSION['name'],
    $adminLocation
]);

auditLog($targetIsStaff ? 'staff_deactivated' : 'player_deactivated', 'user', $userId,
    ($targetIsStaff ? 'Staff member' : 'Player') . ': ' . $targetUser['FirstName'] . ' ' . $targetUser['LastName']);

sendJSON([
    'success' => true,
    'message' => 'User deactivated successfully'
]);
    } catch(PDOException $e) {
        error_log('Database error in users.php DELETE: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
?>