<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
// ============================================
// KBF-UK: EMAIL CODE AT REGISTRATION (shared rules)
//
// Used by api/email-code.php (send and check a code) and
// api/registration.php (the form must carry a valid token).
//
//   kbfEmailCodeReady($pdo)        true once database/email-verification.sql
//                                  has run; until then registration works as
//                                  before, with no code step
//   kbfEmailCodeHash($email, $code) keyed hash of a code (the code itself is
//                                  never stored)
//   kbfRegistrationTokenMake / Check / Use   the short-lived signed token
//                                  given after the right code: same email,
//                                  used once, within 30 minutes
//
// Limits: 3 codes per email and 10 per IP address in an hour, one code a
// minute per email, 5 wrong attempts make a code useless, codes last 10
// minutes. The nightly job deletes rows older than a day.
// ============================================

const KBF_EMAIL_CODE_MINUTES = 10;
const KBF_EMAIL_CODE_MAX_ATTEMPTS = 5;
const KBF_EMAIL_CODE_PER_EMAIL_HOUR = 3;
const KBF_EMAIL_CODE_PER_IP_HOUR = 10;
const KBF_EMAIL_CODE_RESEND_SECONDS = 60;
const KBF_REGISTRATION_TOKEN_SECONDS = 1800;

// True when the EmailVerificationCodes table exists (cached per request)
function kbfEmailCodeReady($pdo) {
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (int) $pdo->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailVerificationCodes'
            ")->fetchColumn() > 0;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

// One form of an address for storing and comparing
function kbfEmailCodeNormalise($email) {
    return mb_strtolower(trim((string) $email), 'UTF-8');
}

// Server-only key for code hashes and tokens, made from secrets already in
// .env (no new key to set up). Changing DB_PASS or RECAPTCHA_SECRET_KEY only
// makes codes and tokens issued before the change invalid.
function kbfEmailCodeKey() {
    $parts = [
        'kbf-email-code-v1',
        defined('DB_PASS') ? (string) DB_PASS : '',
        (string) getenv('RECAPTCHA_SECRET_KEY'),
        (string) getenv('TWO_FACTOR_KEY'),
    ];
    return hash('sha256', implode('|', $parts), true);
}

function kbfEmailCodeHash($email, $code) {
    return hash_hmac('sha256', kbfEmailCodeNormalise($email) . '|' . (string) $code, kbfEmailCodeKey());
}

// A random 6-digit code (leading zeros kept)
function kbfEmailCodeNew() {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function kbfBase64UrlEncode($bytes) {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function kbfBase64UrlDecode($text) {
    $text = strtr((string) $text, '-_', '+/');
    $pad = strlen($text) % 4;
    if ($pad) {
        $text .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($text, true);
}

// The token given after the right code: row id, email and time, signed
function kbfRegistrationTokenMake($rowId, $email) {
    $payload = kbfBase64UrlEncode(json_encode([
        'i' => (int) $rowId,
        'e' => kbfEmailCodeNormalise($email),
        't' => time(),
    ]));
    $sig = kbfBase64UrlEncode(hash_hmac('sha256', $payload, kbfEmailCodeKey(), true));
    return $payload . '.' . $sig;
}

// Checks a token for this email without using it up.
// Returns ['ok' => true, 'id' => row id] or ['ok' => false, 'message' => ...]
function kbfRegistrationTokenCheck($pdo, $token, $email) {
    $missing = ['ok' => false, 'message' => 'Please confirm your email address with the 6-digit code we send you.'];
    $expired = ['ok' => false, 'message' => 'Your email confirmation has expired. Please change the email and send a new code.'];
    $token = is_string($token) ? trim($token) : '';
    if ($token === '' || strlen($token) > 500 || substr_count($token, '.') !== 1) {
        return $missing;
    }
    list($payload, $sig) = explode('.', $token, 2);
    $expected = kbfBase64UrlEncode(hash_hmac('sha256', $payload, kbfEmailCodeKey(), true));
    if (!hash_equals($expected, $sig)) {
        return $missing;
    }
    $data = json_decode((string) kbfBase64UrlDecode($payload), true);
    if (!is_array($data) || !isset($data['i'], $data['e'], $data['t'])) {
        return $missing;
    }
    if (!hash_equals((string) $data['e'], kbfEmailCodeNormalise($email))) {
        return ['ok' => false, 'message' => 'The email address was changed after it was confirmed. Please confirm the new address with a code.'];
    }
    if (time() - (int) $data['t'] > KBF_REGISTRATION_TOKEN_SECONDS || (int) $data['t'] > time() + 60) {
        return $expired;
    }
    $stmt = $pdo->prepare("
        SELECT Id FROM EmailVerificationCodes
        WHERE Id = ? AND Email = ? AND VerifiedDate IS NOT NULL AND UsedDate IS NULL
          AND VerifiedDate > DATE_SUB(NOW(), INTERVAL " . (int) KBF_REGISTRATION_TOKEN_SECONDS . " SECOND)
    ");
    $stmt->execute([(int) $data['i'], (string) $data['e']]);
    if (!$stmt->fetchColumn()) {
        return $expired;
    }
    return ['ok' => true, 'id' => (int) $data['i']];
}

// Uses the token up (once only). True when this call used it.
function kbfRegistrationTokenUse($pdo, $rowId) {
    $stmt = $pdo->prepare("
        UPDATE EmailVerificationCodes SET UsedDate = NOW()
        WHERE Id = ? AND VerifiedDate IS NOT NULL AND UsedDate IS NULL
    ");
    $stmt->execute([(int) $rowId]);
    return $stmt->rowCount() === 1;
}
