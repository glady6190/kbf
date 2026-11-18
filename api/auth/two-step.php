<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// ============================================
// 2-STEP VERIFICATION - SIGN-IN AND YOUR OWN SETTINGS
//
//   GET  ?action=status                 your 2-step state (signed in, or pending setup)
//   POST {action:'verify', code}        pending sign-in: a 6-digit code
//   POST {action:'verify', backupCode}  pending sign-in: a backup code
//   POST {action:'beginSetup'}          new secret for the QR code (kept in the session until confirmed)
//   POST {action:'confirmSetup', code}  turn 2-step on; returns 10 backup codes (shown once)
//   POST {action:'regenerateBackupCodes', code}  replace backup codes (needs a current code)
//   POST {action:'disable', code}       turn it off (needs a current code; not when required)
//
// CSRF on every POST. Setup and changes are blocked while impersonating.
// Codes, secrets and backup codes are never logged.
// ============================================

$method = $_SERVER['REQUEST_METHOD'];
$pending = getTwoStepPending();
$signedIn = isLoggedIn();

// Who is this about: the signed-in user, or the pending user
if ($signedIn) {
    $subjectId = (int) $_SESSION['user_id'];
} elseif ($pending !== null) {
    $subjectId = (int) $pending['user_id'];
} else {
    sendJSON(['success' => false, 'message' => 'Your sign-in has expired. Please sign in again.', 'restart' => true], 401);
}

if ($method === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}

// Wrong-code reply; ends a pending sign-in after too many
function twoStepWrongCode($subjectId, $isPendingSignIn, $message) {
    if ($isPendingSignIn) {
        if (kbfPendingWrongCode($subjectId)) {
            sendJSON([
                'success' => false,
                'message' => 'Too many wrong codes. Please sign in again.',
                'restart' => true
            ], 401);
        }
    } else {
        kbfRecordTwoStepAttempt($subjectId, false);
    }
    sendJSON(['success' => false, 'message' => $message], 401);
}

// Refuse when this person has had too many wrong codes recently
function twoStepRateLimit($subjectId, $endPending) {
    if (kbfTwoStepTooManyFailures($subjectId)) {
        if ($endPending) {
            clearTwoStepPending();
        }
        auditLog('two_step_lockout', 'user', $subjectId, 'Too many wrong codes in 15 minutes', $subjectId);
        sendJSON([
            'success' => false,
            'message' => 'Too many wrong codes. Please try again in 15 minutes.',
            'restart' => $endPending
        ], 429);
    }
}

function twoStepStatusPayload($state, $pendingSetup) {
    return [
        'configured' => twoFactorConfigured(),
        'enabled' => $state['enabled'],
        'enabledDate' => $state['enabledDate'],
        'unusedBackupCodes' => $state['enabled'] ? kbfUnusedBackupCodeCount($state['userId']) : 0,
        'mustUse' => $state['mustUse'],
        'mayUse' => $state['mayUse'] || $state['mustUse'],
        'canTurnOff' => $state['enabled'] && !$state['mustUse'],
        'accountName' => $state['email'],
        'pendingSetup' => $pendingSetup
    ];
}

try {
    $state = twoFactorUserState($subjectId);
    if (!$state) {
        clearTwoStepPending();
        sendJSON(['success' => false, 'message' => 'Your sign-in has expired. Please sign in again.', 'restart' => true], 401);
    }
    $pendingSetup = !$signedIn && $pending !== null && $pending['mode'] === 'setup';
    $pendingVerify = !$signedIn && $pending !== null && $pending['mode'] === 'verify';

    // ---------- STATUS ----------
    if ($method === 'GET') {
        if (($_GET['action'] ?? 'status') !== 'status') {
            sendJSON(['success' => false, 'message' => 'Invalid action'], 400);
        }
        if ($pendingVerify) {
            sendJSON(['success' => false, 'message' => 'Please enter your code first.'], 403);
        }
        sendJSON(['success' => true, 'twoStep' => twoStepStatusPayload($state, $pendingSetup)]);
    }

    if ($method !== 'POST') {
        sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
    }
    $action = is_string($data['action'] ?? null) ? $data['action'] : '';

    // ---------- VERIFY (pending sign-in) ----------
    if ($action === 'verify') {
        if (!$pendingVerify) {
            sendJSON(['success' => false, 'message' => 'Your sign-in has expired. Please sign in again.', 'restart' => true], 401);
        }
        twoStepRateLimit($subjectId, true);

        $usedBackup = false;
        $remaining = null;
        if (isset($data['backupCode']) && is_string($data['backupCode']) && trim($data['backupCode']) !== '') {
            $remaining = kbfUseBackupCode($subjectId, $data['backupCode']);
            if ($remaining === false) {
                twoStepWrongCode($subjectId, true, 'That backup code is not valid or has already been used.');
            }
            $usedBackup = true;
        } else {
            if (kbfCleanTotpInput($data['code'] ?? null) === null) {
                sendJSON(['success' => false, 'message' => 'Please enter the 6-digit code.'], 400);
            }
            if (!kbfVerifyAndConsumeTotp($subjectId, $data['code'])) {
                twoStepWrongCode($subjectId, true, 'That code is not right. Please try the latest code from your app.');
            }
        }

        kbfRecordTwoStepAttempt($subjectId, true);
        if (!completeSignIn($subjectId)) {
            sendJSON(['success' => false, 'message' => 'This account cannot sign in.', 'restart' => true], 401);
        }
        if ($usedBackup) {
            auditLog('two_step_backup_code_used', 'user', $subjectId, 'Backup codes left: ' . (int) $remaining);
        }

        sendJSON([
            'success' => true,
            'redirectUrl' => '/admin/dashboard',
            'usedBackupCode' => $usedBackup,
            'backupCodesRemaining' => $usedBackup ? (int) $remaining : null,
            'csrf_token' => generateCSRFToken()
        ]);
    }

    if ($pendingVerify) {
        sendJSON(['success' => false, 'message' => 'Please enter your code first.'], 403);
    }

    // Changes to 2-step are blocked while viewing as someone else
    requireNotImpersonating();

    // ---------- BEGIN SETUP ----------
    if ($action === 'beginSetup') {
        if (!twoFactorConfigured()) {
            sendJSON(['success' => false, 'message' => KBF_TWO_STEP_NOT_CONFIGURED], 503);
        }
        if ($state['enabled']) {
            sendJSON(['success' => false, 'message' => '2-step verification is already on.'], 409);
        }
        if (!$state['mayUse'] && !$state['mustUse']) {
            sendJSON(['success' => false, 'message' => '2-step verification is not available for your account yet.'], 403);
        }

        $secret = kbfNewTotpSecret();
        // Kept encrypted in the session until the first correct code
        $_SESSION['two_step_setup'] = [
            'user_id' => $subjectId,
            'secret' => kbfEncryptTwoFactorSecret($secret),
            'created' => time()
        ];

        sendJSON([
            'success' => true,
            'secret' => $secret,
            'otpauthUri' => kbfOtpauthUri($secret, $state['email']),
            'issuer' => KBF_TOTP_ISSUER,
            'accountName' => $state['email']
        ]);
    }

    // ---------- CONFIRM SETUP ----------
    if ($action === 'confirmSetup') {
        if (!twoFactorConfigured()) {
            sendJSON(['success' => false, 'message' => KBF_TWO_STEP_NOT_CONFIGURED], 503);
        }
        if ($state['enabled']) {
            sendJSON(['success' => false, 'message' => '2-step verification is already on.'], 409);
        }
        $setup = $_SESSION['two_step_setup'] ?? null;
        if (!is_array($setup) || (int) $setup['user_id'] !== $subjectId || time() - (int) $setup['created'] > 1800) {
            sendJSON(['success' => false, 'message' => 'Setup has expired. Please start again.', 'restartSetup' => true], 409);
        }
        twoStepRateLimit($subjectId, $pendingSetup);

        $secret = kbfDecryptTwoFactorSecret($setup['secret']);
        $step = $secret !== null ? kbfFindTotpStep($secret, $data['code'] ?? null, null) : null;
        if ($step === null) {
            twoStepWrongCode($subjectId, $pendingSetup, 'That code is not right. Check the time on your phone and try the latest code.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE Users
            SET TwoFactorSecret = ?, TwoFactorEnabledDate = NOW(), TwoFactorLastStep = ?
            WHERE UserId = ?
        ");
        $stmt->execute([$setup['secret'], $step, $subjectId]);
        $backupCodes = kbfCreateBackupCodes($subjectId);
        $pdo->commit();

        unset($_SESSION['two_step_setup']);
        kbfRecordTwoStepAttempt($subjectId, true);
        auditLog('two_step_enabled', 'user', $subjectId, '2-step verification set up', $subjectId);

        // Forced setup during sign-in: now create the full session
        if ($pendingSetup && !completeSignIn($subjectId)) {
            sendJSON(['success' => false, 'message' => 'This account cannot sign in.', 'restart' => true], 401);
        }

        sendJSON([
            'success' => true,
            'message' => '2-step verification is on',
            'backupCodes' => $backupCodes,
            'redirectUrl' => '/admin/dashboard',
            'csrf_token' => generateCSRFToken()
        ]);
    }

    // Everything below needs a full session and 2-step already on
    if (!$signedIn) {
        sendJSON(['success' => false, 'message' => 'Please finish setting up 2-step verification first.'], 403);
    }

    // ---------- NEW BACKUP CODES ----------
    if ($action === 'regenerateBackupCodes') {
        if (!$state['enabled']) {
            sendJSON(['success' => false, 'message' => '2-step verification is not on.'], 409);
        }
        twoStepRateLimit($subjectId, false);
        if (!kbfVerifyAndConsumeTotp($subjectId, $data['code'] ?? null)) {
            twoStepWrongCode($subjectId, false, 'That code is not right. Please try the latest code from your app.');
        }
        kbfRecordTwoStepAttempt($subjectId, true);

        $pdo->beginTransaction();
        $backupCodes = kbfCreateBackupCodes($subjectId);
        $pdo->commit();

        auditLog('two_step_backup_codes_created', 'user', $subjectId, 'New set of backup codes; old codes no longer work');
        sendJSON(['success' => true, 'backupCodes' => $backupCodes]);
    }

    // ---------- TURN OFF ----------
    if ($action === 'disable') {
        if (!$state['enabled']) {
            sendJSON(['success' => false, 'message' => '2-step verification is not on.'], 409);
        }
        if ($state['mustUse']) {
            sendJSON(['success' => false, 'message' => 'You are required to use 2-step verification, so it cannot be turned off.'], 403);
        }
        twoStepRateLimit($subjectId, false);
        if (!kbfVerifyAndConsumeTotp($subjectId, $data['code'] ?? null)) {
            twoStepWrongCode($subjectId, false, 'That code is not right. Please try the latest code from your app.');
        }
        kbfRecordTwoStepAttempt($subjectId, true);

        $pdo->beginTransaction();
        kbfClearTwoFactor($subjectId);
        $pdo->commit();

        auditLog('two_step_disabled', 'user', $subjectId, '2-step verification turned off by the account owner');
        sendJSON(['success' => true, 'message' => '2-step verification is off']);
    }

    sendJSON(['success' => false, 'message' => 'Invalid action'], 400);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('two-step.php error: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'An error occurred. Please try again later.'], 500);
}
?>
