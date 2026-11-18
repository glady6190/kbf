<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// Two uses:
//  1. The forced change after signing in with a temporary password
//     (temporary session: must_change_password + temp_user_id).
//  2. A signed-in user changing their own password by giving the current one.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Blocked while a Super Admin is viewing as someone else
requireNotImpersonating();

// Same strength rules as the forced change and password reset
function changePasswordStrengthError($password) {
    if (!is_string($password) || strlen($password) < 8) {
        return 'Password must be at least 8 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number';
    }
    return null;
}

$isForcedChange = isset($_SESSION['must_change_password']) && isset($_SESSION['temp_user_id']);

// ============================================
// 2. SIGNED-IN USER CHANGES THEIR OWN PASSWORD
// Login (401), then CSRF (403), then the current password.
// ============================================
if (!$isForcedChange) {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || !isset($input['currentPassword']) || !isset($input['newPassword'])
        || !is_string($input['currentPassword']) || !is_string($input['newPassword'])) {
        sendJSON(['success' => false, 'message' => 'Current and new password required'], 400);
    }

    $strengthError = changePasswordStrengthError($input['newPassword']);
    if ($strengthError !== null) {
        sendJSON(['success' => false, 'message' => $strengthError], 400);
    }

    $selfUserId = (int) $_SESSION['user_id'];

    try {
        $stmt = $pdo->prepare("SELECT UserId, PasswordHash FROM Users WHERE UserId = ? AND IsActive = 1");
        $stmt->execute([$selfUserId]);
        $self = $stmt->fetch();

        if (!$self || empty($self['PasswordHash']) || !password_verify($input['currentPassword'], $self['PasswordHash'])) {
            sendJSON(['success' => false, 'message' => 'Current password is incorrect'], 401);
        }

        if (password_verify($input['newPassword'], $self['PasswordHash'])) {
            sendJSON(['success' => false, 'message' => 'The new password must be different from the current one'], 400);
        }

        $stmt = $pdo->prepare("UPDATE Users SET PasswordHash = ?, RequirePasswordChange = 0 WHERE UserId = ?");
        $stmt->execute([password_hash($input['newPassword'], PASSWORD_DEFAULT), $selfUserId]);

        auditLog('password_changed', 'user', $selfUserId, null);

        sendJSON(['success' => true, 'message' => 'Password changed successfully']);
    } catch (PDOException $e) {
        error_log('Own password change error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'An error occurred'], 500);
    }
}

// ============================================
// 1. FORCED CHANGE (unchanged)
// ============================================

// Validate CSRF token
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCSRFToken($token)) {
    sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
}

// Check if user has temporary session for password change
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['must_change_password']) || !isset($_SESSION['temp_user_id'])) {
    sendJSON(['success' => false, 'message' => 'Unauthorized access'], 401);
}

$userId = $_SESSION['temp_user_id'];

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['currentPassword']) || !isset($data['newPassword'])) {
    sendJSON(['success' => false, 'message' => 'Current and new password required'], 400);
}

$currentPassword = $data['currentPassword'];
$newPassword = $data['newPassword'];

// Validate new password
if (strlen($newPassword) < 8) {
    sendJSON(['success' => false, 'message' => 'Password must be at least 8 characters'], 400);
}

if (!preg_match('/[A-Z]/', $newPassword)) {
    sendJSON(['success' => false, 'message' => 'Password must contain at least one uppercase letter'], 400);
}

if (!preg_match('/[a-z]/', $newPassword)) {
    sendJSON(['success' => false, 'message' => 'Password must contain at least one lowercase letter'], 400);
}

if (!preg_match('/[0-9]/', $newPassword)) {
    sendJSON(['success' => false, 'message' => 'Password must contain at least one number'], 400);
}

try {
    // Get user
    // Only someone who can still sign in may finish the forced change
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.Email, u.PasswordHash, u.Role, u.FirstName, u.LastName, u.CategoryId, u.ClubId
        FROM Users u
        WHERE u.UserId = ? AND " . KBF_CAN_SIGN_IN_SQL . "
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJSON(['success' => false, 'message' => 'User not found'], 404);
    }
    
    // Verify current password
    if (!password_verify($currentPassword, $user['PasswordHash'])) {
        sendJSON(['success' => false, 'message' => 'Current password is incorrect'], 401);
    }
    
    // Hash new password
    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update password and clear RequirePasswordChange flag
    $stmt = $pdo->prepare("
        UPDATE Users 
        SET PasswordHash = ?, RequirePasswordChange = 0 
        WHERE UserId = ?
    ");
    $stmt->execute([$newPasswordHash, $userId]);
    
    // Clear temporary session and create full session
    unset($_SESSION['temp_user_id']);
    unset($_SESSION['temp_email']);
    unset($_SESSION['must_change_password']);

    // 2-step verification after the forced change: pending state only
    $twoStepNext = twoStepNextStep($user['UserId']);
    if ($twoStepNext !== null) {
        startTwoStepPending($user['UserId'], $twoStepNext);
        auditLog('password_change_forced', 'user', $userId, null, (int) $userId);
        sendJSON([
            'success' => true,
            'message' => 'Password changed successfully',
            'twoStepRequired' => true,
            'mode' => $twoStepNext,
            'csrf_token' => generateCSRFToken(),
            'redirectUrl' => $twoStepNext === 'verify' ? '/two-step' : '/admin/two-step'
        ]);
    }
    
    // Regenerate session ID
    session_regenerate_id(true);
    
    // Set full session
    $_SESSION['user_id'] = $user['UserId'];
    $_SESSION['email'] = $user['Email'];
    $_SESSION['role'] = $user['Role'];
    $_SESSION['name'] = $user['FirstName'] . ' ' . $user['LastName'];
    $_SESSION['category_id'] = $user['CategoryId'];
    $_SESSION['club_id'] = $user['ClubId'];
    $_SESSION['user_fingerprint'] = generateUserFingerprint();
    $_SESSION['CREATED'] = time();
    $_SESSION['LAST_ACTIVITY'] = time();
    
    // Update last login
    $stmt = $pdo->prepare("UPDATE Users SET LastLoginDate = NOW() WHERE UserId = ?");
    $stmt->execute([$userId]);

    // Audit: forced password change completed
    auditLog('password_change_forced', 'user', $userId, null);

    sendJSON([
        'success' => true,
        'message' => 'Password changed successfully',
        'user' => [
            'role' => $user['Role']
        ]
    ]);
    
} catch(PDOException $e) {
    error_log('Password change error: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'An error occurred'], 500);
}
?>