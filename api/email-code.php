<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/email-verification.php';

// ============================================
// EMAIL CODE AT REGISTRATION (public)
//
//   GET  ?action=status   {required}: whether the registration form asks for
//                         a code (false until database/email-verification.sql
//                         has run)
//   POST action=send      {email}: sends a 6-digit code (registration_code,
//                         important email through the shared sender)
//   POST action=verify    {email, code}: on the right code, returns a
//                         short-lived signed token for the registration form
//
// Replies never say whether an address already belongs to a member or an
// application: every valid address gets a code. The rules and limits are in
// api/email-verification.php.
// ============================================

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (($_GET['action'] ?? '') !== 'status') {
        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);
    }
    sendJSON(['success' => true, 'required' => kbfEmailCodeReady($pdo)]);
}

if ($method !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

// The same switches as the registration form itself
if (kbfSettingInt('maintenance_mode', 0, 0, 1) === 1) {
    sendJSON(['success' => false, 'message' => (string) kbfSetting('maintenance_message', 'We are making some improvements. Please check back shortly.')], 503);
}
if (kbfSettingInt('registration_open', 1, 0, 1) === 0) {
    sendJSON(['success' => false, 'message' => (string) kbfSetting('registration_closed_message', 'Registration is closed for now. Please check back soon.')], 403);
}
if (!kbfEmailCodeReady($pdo)) {
    sendJSON(['success' => false, 'message' => 'Email codes are not needed yet. Please carry on with the form.'], 409);
}

// JSON or form body
$input = $_POST;
if (!$input) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
}
$action = (string) ($input['action'] ?? '');
$rawEmail = is_string($input['email'] ?? null) ? trim($input['email']) : '';
$email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);
if (!$email || strlen($email) > 255) {
    sendJSON(['success' => false, 'field' => 'email', 'message' => 'Please enter a valid email address.'], 400);
}
$emailKey = kbfEmailCodeNormalise($email);
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

try {
    // ---------- send a code ----------
    if ($action === 'send') {
        // One code a minute per address
        $stmt = $pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, MAX(CreatedDate), NOW()) FROM EmailVerificationCodes WHERE Email = ?
        ");
        $stmt->execute([$emailKey]);
        $since = $stmt->fetchColumn();
        if ($since !== null && $since !== false && (int) $since < KBF_EMAIL_CODE_RESEND_SECONDS) {
            $wait = KBF_EMAIL_CODE_RESEND_SECONDS - max(0, (int) $since);
            sendJSON(['success' => false, 'retryAfter' => $wait,
                'message' => 'Please wait ' . $wait . ' seconds before asking for another code.'], 429);
        }

        // At most 3 codes per address and 10 per IP address in an hour
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM EmailVerificationCodes WHERE Email = ? AND CreatedDate > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$emailKey]);
        if ((int) $stmt->fetchColumn() >= KBF_EMAIL_CODE_PER_EMAIL_HOUR) {
            sendJSON(['success' => false, 'message' => 'We have already sent 3 codes to this address in the last hour. Please check your inbox and spam folder, or try again later.'], 429);
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM EmailVerificationCodes WHERE IpAddress = ? AND CreatedDate > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$ip]);
        if ((int) $stmt->fetchColumn() >= KBF_EMAIL_CODE_PER_IP_HOUR) {
            sendJSON(['success' => false, 'message' => 'Too many codes have been asked for from your connection. Please try again in an hour.'], 429);
        }

        $code = kbfEmailCodeNew();
        $stmt = $pdo->prepare("
            INSERT INTO EmailVerificationCodes (Email, CodeHash, ExpiresDate, Attempts, CreatedDate, IpAddress)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . (int) KBF_EMAIL_CODE_MINUTES . " MINUTE), 0, NOW(), ?)
        ");
        $stmt->execute([$emailKey, kbfEmailCodeHash($emailKey, $code), $ip]);
        $rowId = (int) $pdo->lastInsertId();

        // Important email through the shared sender; the code is a secret,
        // so the stored copy never holds it
        require_once __DIR__ . '/notify.php';
        $results = notify('registration_code', [
            'person' => ['email' => $email, 'firstName' => '', 'lastName' => ''],
            'values' => ['code' => $code],
            'secrets' => ['code'],
            'relatedType' => 'registration',
        ]);
        if (($results['person'] ?? '') !== 'sent') {
            // Not counted against the address: the code never arrived
            $pdo->prepare("DELETE FROM EmailVerificationCodes WHERE Id = ?")->execute([$rowId]);
            error_log('email-code.php: registration code not sent (' . ($results['person'] ?? 'unknown') . ')');
            sendJSON(['success' => false, 'message' => 'We could not send the code just now. Please try again in a few minutes.'], 503);
        }

        sendJSON([
            'success' => true,
            'message' => 'We have sent a 6-digit code to ' . $email . '. It is valid for ' . KBF_EMAIL_CODE_MINUTES . ' minutes.',
            'expiresIn' => KBF_EMAIL_CODE_MINUTES * 60,
            'resendAfter' => KBF_EMAIL_CODE_RESEND_SECONDS,
        ]);
    }

    // ---------- check a code ----------
    if ($action === 'verify') {
        $code = is_string($input['code'] ?? null) ? preg_replace('/\s+/', '', $input['code']) : '';
        if (!preg_match('/^\d{6}$/', $code)) {
            sendJSON(['success' => false, 'field' => 'code', 'message' => 'Please enter the 6-digit code from the email.'], 400);
        }

        // The latest code for this address only
        $stmt = $pdo->prepare("
            SELECT Id, CodeHash, Attempts, VerifiedDate, (ExpiresDate > NOW()) AS Live
            FROM EmailVerificationCodes WHERE Email = ? ORDER BY Id DESC LIMIT 1
        ");
        $stmt->execute([$emailKey]);
        $row = $stmt->fetch();
        if (!$row || $row['VerifiedDate'] !== null) {
            sendJSON(['success' => false, 'field' => 'code', 'message' => 'This code is no longer valid. Please send a new code.'], 400);
        }
        if ((int) $row['Live'] !== 1) {
            sendJSON(['success' => false, 'field' => 'code', 'message' => 'This code has expired. Please send a new code.'], 400);
        }

        // Count the attempt first, so no more than 5 are ever checked
        $stmt = $pdo->prepare("
            UPDATE EmailVerificationCodes SET Attempts = Attempts + 1
            WHERE Id = ? AND Attempts < ? AND VerifiedDate IS NULL AND ExpiresDate > NOW()
        ");
        $stmt->execute([(int) $row['Id'], KBF_EMAIL_CODE_MAX_ATTEMPTS]);
        if ($stmt->rowCount() !== 1) {
            sendJSON(['success' => false, 'field' => 'code', 'message' => 'Too many wrong codes. Please send a new code.'], 400);
        }
        $attempts = (int) $row['Attempts'] + 1;

        if (!hash_equals((string) $row['CodeHash'], kbfEmailCodeHash($emailKey, $code))) {
            $left = KBF_EMAIL_CODE_MAX_ATTEMPTS - $attempts;
            sendJSON(['success' => false, 'field' => 'code', 'message' => $left > 0
                ? 'That code is not right. Please check the email and try again (' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left).'
                : 'Too many wrong codes. Please send a new code.'], 400);
        }

        $stmt = $pdo->prepare("UPDATE EmailVerificationCodes SET VerifiedDate = NOW() WHERE Id = ? AND VerifiedDate IS NULL");
        $stmt->execute([(int) $row['Id']]);
        if ($stmt->rowCount() !== 1) {
            sendJSON(['success' => false, 'field' => 'code', 'message' => 'This code is no longer valid. Please send a new code.'], 400);
        }

        sendJSON([
            'success' => true,
            'message' => 'Email verified',
            'token' => kbfRegistrationTokenMake((int) $row['Id'], $emailKey),
            'validFor' => KBF_REGISTRATION_TOKEN_SECONDS,
        ]);
    }

    sendJSON(['success' => false, 'message' => 'Unknown action'], 400);

} catch (PDOException $e) {
    error_log('email-code.php: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
