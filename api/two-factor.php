<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
// ============================================
// KBF-UK: 2-STEP VERIFICATION (authenticator app)
// Included by config.php, so it is available wherever config.php is.
//
// - TOTP (RFC 6238): 6 digits, 30-second steps, HMAC-SHA1, one step either
//   side accepted for clock drift. A code is only accepted when its step is
//   newer than Users.TwoFactorLastStep, so a code can never be reused.
// - Base32 (RFC 4648) written here; no Composer package.
// - The secret (20 random bytes, base32) is stored encrypted with
//   AES-256-GCM using TWO_FACTOR_KEY from .env (64 hex characters = 32 bytes).
// - Backup codes: 10 codes of 10 characters, shown once, stored as hashes.
// - Codes are never sent by email and never logged.
//
// Sign-in: after the password (and any forced password change) a person who
// has 2-step on, or must use it, gets a PENDING state holding only their user
// id (no full session). Only a correct code, or finishing setup, creates the
// full session (completeSignIn()).
// ============================================

const KBF_TOTP_DIGITS = 6;
const KBF_TOTP_PERIOD = 30;
const KBF_TOTP_DRIFT_STEPS = 1;
const KBF_TOTP_ISSUER = 'KBF-UK';

const KBF_TWO_STEP_PENDING_LIFETIME = 600;   // 10 minutes
const KBF_TWO_STEP_MAX_PENDING_FAILURES = 5; // per pending sign-in
const KBF_TWO_STEP_MAX_FAILURES = 10;        // per person within LOCKOUT_TIME

const KBF_BACKUP_CODE_COUNT = 10;
const KBF_BACKUP_CODE_LENGTH = 10;
// Letters and digits without easily confused characters (no 0 O 1 I L)
const KBF_BACKUP_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

const KBF_TWO_STEP_POLICIES = ['off', 'optional', 'required_admins', 'required_all'];
const KBF_TWO_STEP_NOT_CONFIGURED = '2-step verification is not configured on this server';

// ============================================
// BASE32 (RFC 4648, no padding on output)
// ============================================
function kbfBase32Encode($bytes) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    $buffer = 0;
    $bits = 0;
    $length = strlen($bytes);
    for ($i = 0; $i < $length; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $output .= $alphabet[($buffer >> $bits) & 31];
        }
        $buffer &= (1 << $bits) - 1;
    }
    if ($bits > 0) {
        $output .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $output;
}

// Returns the raw bytes, or false for invalid input
function kbfBase32Decode($text) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[\s=-]+/', '', (string) $text));
    if ($text === '' || !preg_match('/^[A-Z2-7]+$/', $text)) {
        return false;
    }
    $output = '';
    $buffer = 0;
    $bits = 0;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        $buffer = ($buffer << 5) | strpos($alphabet, $text[$i]);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $output .= chr(($buffer >> $bits) & 255);
        }
        $buffer &= (1 << $bits) - 1;
    }
    return $output;
}

// ============================================
// TOTP
// ============================================
function kbfCurrentTotpStep() {
    return (int) floor(time() / KBF_TOTP_PERIOD);
}

// The 6-digit code for a raw secret and a step
function kbfTotpCode($secretBytes, $step) {
    $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $counter, $secretBytes, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
           | (ord($hash[$offset + 1]) << 16)
           | (ord($hash[$offset + 2]) << 8)
           | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** KBF_TOTP_DIGITS)), KBF_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

// Clean a typed code: digits only, exactly 6, or null
function kbfCleanTotpInput($code) {
    if (!is_string($code) && !is_int($code)) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', (string) $code);
    return strlen($digits) === KBF_TOTP_DIGITS ? $digits : null;
}

// The matching step (one step either side of now) that is newer than
// $lastStep, or null. Compared in constant time.
function kbfFindTotpStep($secretBase32, $code, $lastStep) {
    $code = kbfCleanTotpInput($code);
    $secret = kbfBase32Decode($secretBase32);
    if ($code === null || $secret === false) {
        return null;
    }
    $now = kbfCurrentTotpStep();
    $match = null;
    for ($step = $now - KBF_TOTP_DRIFT_STEPS; $step <= $now + KBF_TOTP_DRIFT_STEPS; $step++) {
        if ($lastStep !== null && $step <= (int) $lastStep) {
            continue;
        }
        if (hash_equals(kbfTotpCode($secret, $step), $code)) {
            $match = $step;
        }
    }
    return $match;
}

// otpauth address for the QR code: issuer KBF-UK, account = email
function kbfOtpauthUri($secretBase32, $accountName) {
    $label = rawurlencode(KBF_TOTP_ISSUER) . ':' . rawurlencode((string) $accountName);
    return 'otpauth://totp/' . $label
        . '?secret=' . $secretBase32
        . '&issuer=' . rawurlencode(KBF_TOTP_ISSUER)
        . '&algorithm=SHA1&digits=' . KBF_TOTP_DIGITS . '&period=' . KBF_TOTP_PERIOD;
}

function kbfNewTotpSecret() {
    return kbfBase32Encode(random_bytes(20));
}

// ============================================
// SECRET ENCRYPTION (AES-256-GCM, key TWO_FACTOR_KEY in .env)
// Stored format: v1:base64(12-byte IV . 16-byte tag . ciphertext)
// ============================================

// The 32-byte key, or null when TWO_FACTOR_KEY is missing or not 64 hex characters
function kbfTwoFactorKey() {
    $hex = getenv('TWO_FACTOR_KEY');
    if (!is_string($hex) || $hex === '') {
        $hex = $_ENV['TWO_FACTOR_KEY'] ?? '';
    }
    $hex = trim((string) $hex);
    if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
        return null;
    }
    return hex2bin($hex);
}

function twoFactorConfigured() {
    return kbfTwoFactorKey() !== null
        && function_exists('openssl_encrypt')
        && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
}

function kbfEncryptTwoFactorSecret($plain) {
    $key = kbfTwoFactorKey();
    if ($key === null) {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'kbf-2fa', 16);
    if ($cipher === false) {
        return null;
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

// The base32 secret, or null when it cannot be decrypted
function kbfDecryptTwoFactorSecret($stored) {
    $key = kbfTwoFactorKey();
    if ($key === null || !is_string($stored) || strpos($stored, 'v1:') !== 0) {
        return null;
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'kbf-2fa');
    return $plain === false ? null : $plain;
}

// ============================================
// PEOPLE AND POLICY
// ============================================
function twoFactorPolicy() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT SettingValue FROM Settings WHERE SettingKey = 'two_factor_policy'");
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return in_array($value, KBF_TWO_STEP_POLICIES, true) ? $value : 'off';
    } catch (PDOException $e) {
        error_log('twoFactorPolicy: ' . $e->getMessage());
        return 'off';
    }
}

// Must this person use 2-step? (policy, Super Admin, Main Admin label, flag)
function twoFactorMustUseFor($policy, $isSuperAdmin, array $labels, $requiredFlag) {
    if ($requiredFlag) {
        return true;
    }
    if ($policy === 'required_all') {
        return true;
    }
    if ($policy === 'required_admins') {
        return $isSuperAdmin || in_array('MainAdmin', $labels, true);
    }
    return false;
}

// May this person set it up for themselves?
function twoFactorMayUseFor($policy, $isSuperAdmin, $requiredFlag) {
    if ($policy === 'off') {
        return $isSuperAdmin || $requiredFlag;
    }
    return true; // optional / required_*: every staff member
}

// Everything about one person's 2-step state, or null when not found
function twoFactorUserState($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.Email, u.IsSuperAdmin, u.TwoFactorSecret, u.TwoFactorEnabledDate,
               u.TwoFactorRequired, u.TwoFactorLastStep
        FROM Users u
        WHERE u.UserId = ?
    ");
    $stmt->execute([(int) $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT RoleKey FROM StaffRoles WHERE UserId = ?");
    $stmt->execute([(int) $userId]);
    $labels = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $policy = twoFactorPolicy();
    $isSuper = (int) $row['IsSuperAdmin'] === 1;
    $requiredFlag = (int) $row['TwoFactorRequired'] === 1;
    $enabled = !empty($row['TwoFactorSecret']) && !empty($row['TwoFactorEnabledDate']);

    return [
        'userId' => (int) $row['UserId'],
        'email' => (string) $row['Email'],
        'enabled' => $enabled,
        'enabledDate' => $row['TwoFactorEnabledDate'],
        'storedSecret' => $row['TwoFactorSecret'],
        'lastStep' => $row['TwoFactorLastStep'] === null ? null : (int) $row['TwoFactorLastStep'],
        'requiredFlag' => $requiredFlag,
        'mustUse' => twoFactorMustUseFor($policy, $isSuper, $labels, $requiredFlag),
        'mayUse' => twoFactorMayUseFor($policy, $isSuper, $requiredFlag),
        'policy' => $policy,
    ];
}

// Check a code against the person's saved secret and consume its step.
// True only for a fresh, correct code.
function kbfVerifyAndConsumeTotp($userId, $code) {
    global $pdo;
    $state = twoFactorUserState($userId);
    if (!$state || !$state['enabled']) {
        return false;
    }
    $secret = kbfDecryptTwoFactorSecret($state['storedSecret']);
    if ($secret === null) {
        error_log('2-step: secret could not be decrypted for user ' . (int) $userId);
        return false;
    }
    $step = kbfFindTotpStep($secret, $code, $state['lastStep']);
    if ($step === null) {
        return false;
    }
    // Only one request can consume a step
    $stmt = $pdo->prepare("
        UPDATE Users SET TwoFactorLastStep = ?
        WHERE UserId = ? AND (TwoFactorLastStep IS NULL OR TwoFactorLastStep < ?)
    ");
    $stmt->execute([$step, (int) $userId, $step]);
    return $stmt->rowCount() === 1;
}

// ============================================
// BACKUP CODES
// ============================================
function kbfNormaliseBackupCode($code) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', (string) $code));
}

// Replace the person's backup codes. Returns the plain codes (shown once),
// formatted XXXXX-XXXXX. Call inside a transaction where possible.
function kbfCreateBackupCodes($userId) {
    global $pdo;
    $alphabet = KBF_BACKUP_CODE_ALPHABET;
    $max = strlen($alphabet) - 1;
    $codes = [];
    while (count($codes) < KBF_BACKUP_CODE_COUNT) {
        $code = '';
        for ($i = 0; $i < KBF_BACKUP_CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        $codes[$code] = true;
    }
    $codes = array_keys($codes);

    $stmt = $pdo->prepare("DELETE FROM TwoFactorBackupCodes WHERE UserId = ?");
    $stmt->execute([(int) $userId]);

    $insert = $pdo->prepare("INSERT INTO TwoFactorBackupCodes (UserId, CodeHash, CreatedDate) VALUES (?, ?, NOW())");
    foreach ($codes as $code) {
        $insert->execute([(int) $userId, password_hash($code, PASSWORD_DEFAULT)]);
    }

    return array_map(function ($code) {
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }, $codes);
}

function kbfUnusedBackupCodeCount($userId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM TwoFactorBackupCodes WHERE UserId = ? AND UsedDate IS NULL");
    $stmt->execute([(int) $userId]);
    return (int) $stmt->fetchColumn();
}

// Use a backup code once. Returns the number left, or false when not valid.
function kbfUseBackupCode($userId, $code) {
    global $pdo;
    $code = kbfNormaliseBackupCode($code);
    if (strlen($code) !== KBF_BACKUP_CODE_LENGTH) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT Id, CodeHash FROM TwoFactorBackupCodes WHERE UserId = ? AND UsedDate IS NULL");
    $stmt->execute([(int) $userId]);
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($code, $row['CodeHash'])) {
            $mark = $pdo->prepare("UPDATE TwoFactorBackupCodes SET UsedDate = NOW() WHERE Id = ? AND UsedDate IS NULL");
            $mark->execute([(int) $row['Id']]);
            if ($mark->rowCount() !== 1) {
                return false; // used by a parallel request
            }
            return kbfUnusedBackupCodeCount($userId);
        }
    }
    return false;
}

// Turn 2-step off for a person: secret, dates and backup codes removed
function kbfClearTwoFactor($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        UPDATE Users
        SET TwoFactorSecret = NULL, TwoFactorEnabledDate = NULL, TwoFactorLastStep = NULL
        WHERE UserId = ?
    ");
    $stmt->execute([(int) $userId]);
    $stmt = $pdo->prepare("DELETE FROM TwoFactorBackupCodes WHERE UserId = ?");
    $stmt->execute([(int) $userId]);
}

// ============================================
// WRONG-CODE LIMIT (existing LoginAttempts mechanism)
// Wrong codes are recorded under a per-person key, at most
// KBF_TWO_STEP_MAX_FAILURES within LOCKOUT_TIME.
// ============================================
function kbfTwoStepAttemptKey($userId) {
    return 'two-step:' . (int) $userId;
}

function kbfTwoStepTooManyFailures($userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM LoginAttempts
            WHERE Email = ? AND Success = 0 AND AttemptTime > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([kbfTwoStepAttemptKey($userId), LOCKOUT_TIME]);
        return (int) $stmt->fetchColumn() >= KBF_TWO_STEP_MAX_FAILURES;
    } catch (PDOException $e) {
        error_log('kbfTwoStepTooManyFailures: ' . $e->getMessage());
        return false;
    }
}

function kbfRecordTwoStepAttempt($userId, $success) {
    try {
        recordLoginAttempt(kbfTwoStepAttemptKey($userId), $success);
    } catch (PDOException $e) {
        error_log('kbfRecordTwoStepAttempt: ' . $e->getMessage());
    }
}

// ============================================
// PENDING SIGN-IN STATE (only the user id; never a full session)
// ============================================

// What happens after the password: 'verify', 'setup' or null (full session)
function twoStepNextStep($userId) {
    try {
        $state = twoFactorUserState($userId);
    } catch (PDOException $e) {
        // Columns missing (two-step.sql not run yet): sign in as before
        error_log('twoStepNextStep: ' . $e->getMessage());
        return null;
    }
    if (!$state) {
        return null;
    }
    if ($state['enabled']) {
        return 'verify';
    }
    if ($state['mustUse']) {
        return 'setup';
    }
    return null;
}

function startTwoStepPending($userId, $mode) {
    // Never a full session while pending: the whole old session (including
    // any impersonation keys) is cleared
    kbfClearSessionForSignIn();
    $_SESSION['two_step_pending'] = [
        'user_id' => (int) $userId,
        'mode' => $mode === 'setup' ? 'setup' : 'verify',
        'created' => time(),
        'failures' => 0,
    ];
    $_SESSION['CREATED'] = time();
}

// The pending state, or null (expired states are cleared)
function getTwoStepPending() {
    if (!isset($_SESSION['two_step_pending']) || !is_array($_SESSION['two_step_pending'])) {
        return null;
    }
    $pending = $_SESSION['two_step_pending'];
    if (time() - (int) $pending['created'] > KBF_TWO_STEP_PENDING_LIFETIME) {
        clearTwoStepPending();
        return null;
    }
    return $pending;
}

function clearTwoStepPending() {
    unset($_SESSION['two_step_pending'], $_SESSION['two_step_setup']);
}

// Count a wrong code against the pending sign-in. Returns true when the
// pending state has now ended (too many wrong codes).
function kbfPendingWrongCode($userId) {
    kbfRecordTwoStepAttempt($userId, false);
    if (!isset($_SESSION['two_step_pending'])) {
        return true;
    }
    $_SESSION['two_step_pending']['failures'] = (int) $_SESSION['two_step_pending']['failures'] + 1;
    if ($_SESSION['two_step_pending']['failures'] >= KBF_TWO_STEP_MAX_PENDING_FAILURES) {
        clearTwoStepPending();
        auditLog('two_step_lockout', 'user', (int) $userId, 'Too many wrong codes during sign-in', (int) $userId);
        return true;
    }
    return false;
}

// Create the full session for a person who has passed every step.
// Returns false when the person can no longer sign in.
function completeSignIn($userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.Email, u.Role, u.FirstName, u.LastName, u.CategoryId, u.ClubId
        FROM Users u
        WHERE u.UserId = ? AND " . KBF_CAN_SIGN_IN_SQL . "
    ");
    $stmt->execute([(int) $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        clearTwoStepPending();
        return false;
    }

    // A brand-new session: the whole old one is cleared first
    kbfBeginUserSession($user);

    $stmt = $pdo->prepare("UPDATE Users SET LastLoginDate = NOW() WHERE UserId = ?");
    $stmt->execute([$user['UserId']]);

    auditLog('login', 'user', $user['UserId'], 'After 2-step verification', (int) $user['UserId']);
    return true;
}
?>
