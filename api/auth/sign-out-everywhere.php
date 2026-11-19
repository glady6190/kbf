<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// ============================================
// SIGN OUT EVERYWHERE (your own account)
//   POST (no body needed)
// Ends every session of the signed-in person, on every device, including
// this one, by raising Users.SessionVersion.
// Order of checks: logged in (401), CSRF (403). Blocked while impersonating.
// ============================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($token) || !validateCSRFToken($token)) {
    sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
}

requireNotImpersonating();

$userId = (int) $_SESSION['user_id'];

try {
    if (kbfBumpSessionVersion($userId) === null) {
        sendJSON(['success' => false, 'message' => 'This is not available yet. Please ask a Super Admin to run database/security-hardening.sql.'], 409);
    }
    auditLog('signed_out_everywhere', 'user', $userId, 'Signed out of every device (own account)');
} catch (PDOException $e) {
    error_log('sign-out-everywhere.php: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'An error occurred. Please try again later.'], 500);
}

// This browser too
kbfEndSession();
kbfStaffCookie(false);

sendJSON([
    'success' => true,
    'message' => 'You have been signed out on every device.',
    'redirectUrl' => '/login'
]);
?>
