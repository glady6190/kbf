<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['email']) || !isset($data['password'])) {
    sendJSON(['success' => false, 'message' => 'Email and password required'], 400);
}

$email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
$password = $data['password'];

// The same reply whether or not an account exists for the email
const LOGIN_MISMATCH_MESSAGE = 'That email and password do not match. Check them and try again.';

if (!$email) {
    sendJSON(['success' => false, 'message' => 'Enter your email address in the form name@example.com.'], 400);
}

// Check rate limiting (failed attempts per email and per IP address, within
// the lockout time set on the Security page)
if (!checkLoginAttempts($email, $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
    $lockoutMinutes = max(1, (int) ceil(LOCKOUT_TIME / 60));
    sendJSON([
        'success' => false,
        'locked' => true,
        'lockoutMinutes' => $lockoutMinutes,
        'message' => 'Too many attempts. Please wait ' . $lockoutMinutes . ' minutes and try again.'
    ], 429);
}

try {
    // Get user from database. Only people who can sign in are found:
    // active, sign-in enabled, a password set, and staff (KBF_CAN_SIGN_IN_SQL).
    // Everyone else gets the same "Invalid email or password" reply.
    $stmt = $pdo->prepare("
        SELECT u.*, c.Name as CategoryName, cl.Name as ClubName
        FROM Users u
        LEFT JOIN Categories c ON u.CategoryId = c.CategoryId
        LEFT JOIN Clubs cl ON u.ClubId = cl.ClubId
        WHERE u.Email = ? AND " . KBF_CAN_SIGN_IN_SQL . "
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    // TIMING ATTACK PREVENTION: Always verify password even if user doesn't exist
    $dummyHash = '$2y$10$abcdefghijklmnopqrstuu5S6KRV.NFKlW5pU8FzN4HfO8wq7jGNq'; // Dummy hash for timing consistency
    $passwordHash = $user ? $user['PasswordHash'] : $dummyHash;
    $passwordValid = password_verify($password, $passwordHash);
    
    if (!$user || !$passwordValid) {
        recordLoginAttempt($email, false);
        sendJSON(['success' => false, 'message' => LOGIN_MISMATCH_MESSAGE], 401);
    }

    // Defence in depth: the query above already requires a password
    if (empty($user['PasswordHash'])) {
        recordLoginAttempt($email, false);
        sendJSON(['success' => false, 'message' => LOGIN_MISMATCH_MESSAGE], 401);
    }
    
    // NEW: Check if user needs to change password
    if (isset($user['RequirePasswordChange']) && $user['RequirePasswordChange'] == 1) {
        // Record successful login (they authenticated correctly)
        recordLoginAttempt($email, true);
        
        // Set minimal session for password change
        session_regenerate_id(true);
        $_SESSION['temp_user_id'] = $user['UserId'];
        $_SESSION['temp_email'] = $user['Email'];
        $_SESSION['must_change_password'] = true;
        $_SESSION['CREATED'] = time();
        
        // Generate CSRF token for password change form
        $csrfToken = generateCSRFToken();
        
        sendJSON([
            'success' => true,
            'requirePasswordChange' => true,
            'message' => 'You must change your password before continuing',
            'csrf_token' => $csrfToken,
            'user' => [
                'email' => $user['Email'],
                'name' => $user['FirstName'] . ' ' . $user['LastName']
            ]
        ]);
        exit; // ADD THIS LINE - Stop execution here!
    }
    
    // Record successful login
    recordLoginAttempt($email, true);

    // 2-step verification: someone who has it on, or must use it, gets a
    // pending state holding only their id. The full session is created only
    // after a correct code (or after finishing setup).
    $twoStepNext = twoStepNextStep($user['UserId']);
    if ($twoStepNext !== null) {
        startTwoStepPending($user['UserId'], $twoStepNext);
        sendJSON([
            'success' => true,
            'twoStepRequired' => true,
            'mode' => $twoStepNext,
            'csrf_token' => generateCSRFToken(),
            'redirectUrl' => $twoStepNext === 'verify' ? '/two-step' : '/admin/two-step'
        ]);
    }
    
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    
    // Set session variables
    $_SESSION['user_id'] = $user['UserId'];
    $_SESSION['email'] = $user['Email'];
    $_SESSION['role'] = $user['Role'];
    $_SESSION['name'] = $user['FirstName'] . ' ' . $user['LastName'];
    $_SESSION['category_id'] = $user['CategoryId'];
    $_SESSION['club_id'] = $user['ClubId'];
    $_SESSION['user_fingerprint'] = generateUserFingerprint();
    $_SESSION['CREATED'] = time();
    $_SESSION['LAST_ACTIVITY'] = time();
    
    // Generate CSRF token
    $csrfToken = generateCSRFToken();
    
    // Update last login time
    $stmt = $pdo->prepare("UPDATE Users SET LastLoginDate = NOW() WHERE UserId = ?");
    $stmt->execute([$user['UserId']]);

    // Audit: successful login
    auditLog('login', 'user', $user['UserId'], null);

    // Staff can see the whole site while maintenance mode is on
    kbfStaffCookie(true);

    // Return success
    sendJSON([
        'success' => true,
        'message' => 'Login successful',
        'csrf_token' => $csrfToken,
        'user' => [
            'id' => $user['UserId'],
            'email' => $user['Email'],
            'name' => $user['FirstName'] . ' ' . $user['LastName'],
            'role' => $user['Role'],
            'category' => $user['CategoryName'],
            'club' => $user['ClubName']
        ]
    ]);
    
} catch(PDOException $e) {
    error_log('Login error: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'An error occurred. Please try again later.'], 500);
}
?>