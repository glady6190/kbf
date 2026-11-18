<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// Signing out while impersonating ends the impersonation first (logged),
// then the Super Admin's own sign-out is logged
if (isImpersonating()) {
    endImpersonation('signed out');
}

// Audit: logout (only when someone is actually logged in)
if (isLoggedIn()) {
    auditLog('logout', 'user', $_SESSION['user_id'], null);
}

// Destroy session completely
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// The maintenance-mode staff cookie goes too
kbfStaffCookie(false);

sendJSON([
    'success' => true,
    'message' => 'Logged out successfully'
]);
?>