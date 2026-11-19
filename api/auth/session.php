<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// Session timeout and warning in minutes (the warning is always shorter)
function kbfSessionTimes() {
    $timeout = (int) (SESSION_LIFETIME / 60);
    $warning = kbfSettingInt('session_warning_minutes', 5, 1, 30);
    if ($warning >= $timeout) {
        $warning = max(1, $timeout - 1);
    }
    return [
        'timeoutMinutes' => $timeout,
        'warningMinutes' => $warning,
        'impersonationMinutes' => (int) (kbfImpersonationSeconds() / 60),
    ];
}

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}

// Check if this is a temporary password change session
if (isset($_SESSION['must_change_password']) && 
    $_SESSION['must_change_password'] && 
    isset($_SESSION['temp_user_id'])) {
    
    sendJSON([
        'success' => true,
        'loggedIn' => false,
        'mustChangePassword' => true,
        'csrf_token' => generateCSRFToken(),
        'user' => [
            'email' => $_SESSION['temp_email'] ?? ''
        ]
    ]);
    exit;
}

// 2-step verification pending (password done, code or setup still needed).
// Not signed in: only the user id is held.
$twoStepPending = getTwoStepPending();
if ($twoStepPending !== null) {
    sendJSON([
        'success' => true,
        'loggedIn' => false,
        'mustChangePassword' => false,
        'twoStepPending' => [
            'mode' => $twoStepPending['mode'],
            'secondsLeft' => max(0, (int) $twoStepPending['created'] + KBF_TWO_STEP_PENDING_LIFETIME - time())
        ],
        'csrf_token' => generateCSRFToken()
    ]);
}

// Clean up orphaned password change flag
if (isset($_SESSION['must_change_password']) && !isset($_SESSION['temp_user_id'])) {
    unset($_SESSION['must_change_password']);
}

// Full sessions (isLoggedIn() also checks the session version and the
// absolute 12-hour limit)
if (isLoggedIn()) {

    // Per-user access, read fresh through api/permissions.php
    $access = currentAccess();
    $isSuperAdmin = isSuperAdmin();
    $permissionKeys = $access !== null ? array_values($access['permissions']) : [];
    $staffRoles = currentStaffRoles();

    // Compatibility flags for the existing pages until Stage 3b.
    // Derived from permissions only; the role and club never decide anything.
    $canAccessAdmin = hasAnyPermission([
        'players.approve_registrations', 'players.view_export', 'players.edit_details',
        'players.change_grades', 'players.contact_details',
        'tournaments.approve', 'tournaments.manage_all',
        'content.all_news_gallery', 'content.sponsors', 'content.broadcasts'
    ]);
    $canAccessOrganizer = hasAnyPermission(['tournaments.organise_own', 'content.own_news_gallery']);
    $hasDualRole = $canAccessAdmin && $canAccessOrganizer;
    $activeRole = $canAccessAdmin ? 'admin' : 'organizer';

    $twoStepEnabled = false;
    try {
        $twoStepState = twoFactorUserState($_SESSION['user_id']);
        $twoStepEnabled = $twoStepState ? $twoStepState['enabled'] : false;
    } catch (PDOException $e) {
        $twoStepEnabled = false;
    }

    // Impersonation: the signed-in user above is the person being viewed
    $impersonation = ['active' => false];
    if (isImpersonating()) {
        $roleLabels = ['MainAdmin' => 'Main Admin', 'ZonalAdmin' => 'Zonal Admin', 'Organiser' => 'Organiser'];
        $targetLabel = !empty($staffRoles) ? ($roleLabels[$staffRoles[0]] ?? $staffRoles[0]) : 'Staff';
        $impersonation = [
            'active' => true,
            'targetUserId' => (int) $_SESSION['user_id'],
            'targetName' => $_SESSION['name'],
            'targetLabel' => $targetLabel,
            'superAdminName' => (string) ($_SESSION['impersonator']['name'] ?? ''),
            'secondsLeft' => impersonationSecondsLeft()
        ];
    }

    // Staff can see the whole site while maintenance mode is on
    kbfStaffCookie(true);

    sendJSON([
        'success' => true,
        'loggedIn' => true,
        'mustChangePassword' => false,
        'csrf_token' => generateCSRFToken(),
        // Session timing from the Security page; js/session-manager.js uses it
        'session' => kbfSessionTimes(),
        // Upload limits from Site settings, for the admin pages' own checks
        'uploadLimitsMb' => [
            'photo' => kbfSettingInt('upload_mb_photo', 5, 1, 50),
            'news' => kbfSettingInt('upload_mb_news', 5, 1, 50),
            'gallery' => kbfSettingInt('upload_mb_gallery', 15, 1, 50),
            'poster' => kbfSettingInt('upload_mb_poster', 4, 1, 50),
            'sponsor' => kbfSettingInt('upload_mb_sponsor', 2, 1, 50),
            'emailAttachments' => kbfSettingInt('upload_mb_email_attachments', 5, 1, 50),
        ],
        'user' => [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['name'],
            'email' => $_SESSION['email'],
            'role' => $_SESSION['role'],
            'clubId' => $_SESSION['club_id'] ?? null,
            'categoryId' => $_SESSION['category_id'] ?? null,
            'canAccessAdmin' => $canAccessAdmin,
            'canAccessOrganizer' => $canAccessOrganizer,
            'hasDualRole' => $hasDualRole,
            'activeRole' => $activeRole,
            'isSuperAdmin' => $isSuperAdmin,
            'permissions' => $permissionKeys,
            'staffRoles' => $staffRoles,
            // The signed-in account's own 2-step state (false until two-step.sql is run)
            'twoStepEnabled' => $twoStepEnabled
        ],
        'impersonation' => $impersonation
    ]);
} else {
    kbfStaffCookie(false);
    sendJSON([
        'success' => true,
        'loggedIn' => false,
        'mustChangePassword' => false,
        'session' => kbfSessionTimes()
    ]);
}
?>