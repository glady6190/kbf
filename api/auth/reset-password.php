<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';
require_once 'email-config.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Blocked while a Super Admin is viewing as someone else
requireNotImpersonating();

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate required fields (strings only: a wrong type is a 400)
if (!is_array($data) || !isset($data['token']) || !is_string($data['token']) || trim($data['token']) === '') {
    sendJSON(['success' => false, 'message' => 'Reset token is required'], 400);
}

if (!isset($data['password']) || !is_string($data['password']) || $data['password'] === '') {
    sendJSON(['success' => false, 'message' => 'New password is required'], 400);
}

$token = sanitizeInput($data['token']);
$newPassword = $data['password'];

// Validate password strength
if (strlen($newPassword) < 8) {
    sendJSON([
        'success' => false,
        'message' => 'Password must be at least 8 characters long'
    ], 400);
}

// Additional password strength checks
if (!preg_match('/[A-Z]/', $newPassword)) {
    sendJSON([
        'success' => false,
        'message' => 'Password must contain at least one uppercase letter'
    ], 400);
}

if (!preg_match('/[a-z]/', $newPassword)) {
    sendJSON([
        'success' => false,
        'message' => 'Password must contain at least one lowercase letter'
    ], 400);
}

if (!preg_match('/[0-9]/', $newPassword)) {
    sendJSON([
        'success' => false,
        'message' => 'Password must contain at least one number'
    ], 400);
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Validate token format
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        $pdo->rollBack();
        sendJSON([
            'success' => false,
            'message' => 'Invalid token format'
        ], 400);
    }
    
    
    // Limit: at most 10 failed token lookups (unknown, used or expired
    // links) per IP address per hour
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $failedLookups = kbfRequestCount('reset_fail_ip', $ipAddress, 3600);
    if ($failedLookups !== null && $failedLookups >= 10) {
        $pdo->rollBack();
        sendJSON([
            'success' => false,
            'message' => 'Too many password reset attempts. Please try again in 1 hour.'
        ], 429);
    }

    // Get reset request and lock the row
    $stmt = $pdo->prepare("
        SELECT pr.ResetId, pr.UserId, pr.Email, pr.ExpiresAt, pr.Used,
               u.FirstName, u.LastName, u.Email as UserEmail
        FROM PasswordResets pr
        JOIN Users u ON pr.UserId = u.UserId
        WHERE pr.Token = ? AND " . KBF_CAN_SIGN_IN_SQL . "
        FOR UPDATE
    ");
    // Tokens are stored as SHA-256 hashes; look up by hash
    $stmt->execute([hash('sha256', strtolower($token))]);
    $reset = $stmt->fetch();
    
    // Validate token exists
    if (!$reset) {
        $pdo->rollBack();
        kbfRequestRecord('reset_fail_ip', $ipAddress);
        sendJSON([
            'success' => false,
            'message' => 'Invalid or expired reset link'
        ], 404);
    }
    
    // Check if token has been used
    if ($reset['Used'] == 1) {
        $pdo->rollBack();
        kbfRequestRecord('reset_fail_ip', $ipAddress);
        sendJSON([
            'success' => false,
            'message' => 'This reset link has already been used'
        ], 400);
    }
    
    // Check if token has expired
    $now = new DateTime();
    $expiresAt = new DateTime($reset['ExpiresAt']);
    
    if ($now > $expiresAt) {
        $pdo->rollBack();
        kbfRequestRecord('reset_fail_ip', $ipAddress);
        sendJSON([
            'success' => false,
            'message' => 'This reset link has expired. Please request a new one.'
        ], 400);
    }
    
    // Hash new password
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update user password (a completed reset also clears any forced password change)
    $stmt = $pdo->prepare("
        UPDATE Users
        SET PasswordHash = ?, RequirePasswordChange = 0, LastLoginDate = NOW()
        WHERE UserId = ?
    ");
    $stmt->execute([$passwordHash, $reset['UserId']]);
    
    // Mark token as used
    $stmt = $pdo->prepare("
        UPDATE PasswordResets 
        SET Used = 1 
        WHERE ResetId = ?
    ");
    $stmt->execute([$reset['ResetId']]);
    
    // Delete any other unused tokens for this user (cleanup)
    $stmt = $pdo->prepare("
        DELETE FROM PasswordResets 
        WHERE UserId = ? AND Used = 0 AND ResetId != ?
    ");
    $stmt->execute([$reset['UserId'], $reset['ResetId']]);

    // Sign out every session of this person
    kbfBumpSessionVersion($reset['UserId']);

    // Commit transaction
    $pdo->commit();

    // Audit: password reset completed (the user proved control of the reset link)
    auditLog('password_reset', 'user', $reset['UserId'], null, $reset['UserId']);

    // Send confirmation email (after the commit; a problem never blocks the reset)
    notify('password_reset_completed', [
        'person' => (int) $reset['UserId'],
        'relatedType' => 'user',
        'relatedId' => (int) $reset['UserId'],
    ]);
    
    // Every other session of this user ends through Users.SessionVersion
    // (above); this browser's session is ended here if it is theirs
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $reset['UserId']) {
        kbfEndSession();
    }
    
    sendJSON([
        'success' => true,
        'message' => 'Password reset successfully. You can now log in with your new password.'
    ]);
    
} catch(PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Password reset error: ' . $e->getMessage());
    sendJSON([
        'success' => false,
        'message' => 'An error occurred. Please try again later.'
    ], 500);
}
?>