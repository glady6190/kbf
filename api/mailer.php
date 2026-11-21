<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
// ============================================
// KBF-UK: SHARED EMAIL SENDER
// Every email the site sends goes through kbfSendEmail(). The SMTP set-up
// lives here only (kbfMailSmtpSend), reading .env as before:
//   SMTP_HOST (default smtp.hostinger.com), SMTP_PORT (default 465),
//   SMTP_USERNAME (default noreply@kbf-uk.org), SMTP_PASSWORD.
//
// Every email gets an EmailLog row, written before sending.
//
// Daily allowance = EmailLog rows with Status 'sent' in the last 24 hours,
// split three ways:
//   important  may use the whole email_daily_limit
//   normal     may use the limit minus email_reserve_important
//   bulk       may use the limit minus email_reserve_important minus
//              email_reserve_normal (the "bulk share"), only when no
//              important or normal emails are waiting, and spread through
//              the day: at most the bulk share / 24 (rounded up) per hour
// So everyday emails always have room, even while a broadcast runs.
// Nothing is sent while email_paused_until is in the future.
//
// Optional separate service for broadcasts (bulk emails): when BULK_SMTP_HOST,
// BULK_SMTP_PORT, BULK_SMTP_USERNAME, BULK_SMTP_PASSWORD and BULK_DAILY_LIMIT
// are all set in .env, bulk emails go through that service with its own
// limit (BULK_DAILY_LIMIT in 24 hours, spread at most limit / 24 per hour,
// paused separately by email_bulk_paused_until), and the Hostinger allowance
// counts only the other emails. Otherwise everything works as above.
//
// Temporary failures (connection or sign-in problems): the email waits.
// After KBF_MAIL_TEMP_SKIP_AFTER of them it is skipped until later
// (EmailLog.NextAttemptDate, backing off up to 6 hours), so one email can
// never hold up the others.
//
// Results: 'sent', 'waiting' (the job sends it later) or 'failed'.
//   * The mail server's own rate limit (e.g. SMTP 451, "ratelimit") keeps the
//     email waiting and pauses sending for one hour.
//   * Permanent refusals (SMTP 5xx replies, an address that can never
//     receive mail, a message that cannot be built) become failed at once,
//     with no retry (kbfMailClassifyFailure), so a refused email is tried
//     only once. The email log shows the reason in plain English.
//   * 4xx replies, timeouts, connection or sign-in problems keep the email
//     waiting.
//
// Secrets: anything listed in 'redact' (e.g. a password reset link) is
// replaced before it is stored. Such an email is never left waiting, because
// the stored copy could not be sent: if it cannot go out straight away it is
// marked failed with the reason.
//
// Requires the global $pdo (config.php). Works from web requests and cron.
// ============================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config-helpers.php';

const KBF_MAIL_PRIORITIES = ['important', 'normal', 'bulk'];
const KBF_MAIL_PAUSE_SECONDS = 3600;          // after the mail server's rate limit
const KBF_MAIL_LOCK_NAME = 'kbf_mailer_send';  // MySQL named lock: one send at a time
const KBF_MAIL_LOCK_WAIT = 10;                 // seconds to wait for the lock
const KBF_MAIL_DEFAULT_LIMIT = 100;
const KBF_MAIL_DEFAULT_RESERVE = 20;
const KBF_MAIL_DEFAULT_RESERVE_NORMAL = 10;
const KBF_MAIL_TEMP_SKIP_AFTER = 3;            // temporary failures before an email is skipped for a while
const KBF_MAIL_TEMP_MAX_BACKOFF = 360;         // minutes

// Event keys and their names on the Email and limits page.
// password_reset, password_reset_done and promotion are the stage 1 names,
// kept so older log rows still show a name.
const KBF_MAIL_EVENTS = [
    'registration_received'    => 'Application received',
    'registration_approved'    => 'Registration approved',
    'registration_rejected'    => 'Registration rejected',
    'registration_waiting'     => 'New registration waiting',
    'player_added'             => 'Player added by an admin',
    'promoted'                 => 'Promoted to a higher grade',
    'grade_changed'            => 'Grade changed',
    'eligible_for_promotion'   => 'Eligible for promotion',
    'tournament_submitted'     => 'Tournament submitted',
    'tournament_approved'      => 'Tournament approved',
    'tournament_rejected'      => 'Tournament rejected',
    'results_submitted'        => 'Results submitted',
    'results_approved'         => 'Results approved',
    'results_rejected'         => 'Results rejected',
    'results_overdue'          => 'Results not yet submitted',
    'registration_duplicate'   => 'Already registered or waiting',
    'registration_code'        => 'Registration email code',
    'password_reset_requested' => 'Password reset requested',
    'password_reset_completed' => 'Password reset completed',
    'staff_access_changed'     => 'Staff access changed',
    'season_reset_completed'   => 'Season reset completed',
    'broadcast_finished'       => 'Broadcast finished',
    'daily_summary'            => 'Daily summary',
    'broadcast'                => 'Broadcast',
    'test'                     => 'Test email',
    'password_reset'           => 'Password reset requested',
    'password_reset_done'      => 'Password reset completed',
    'promotion'                => 'Promotion',
];

// Emails whose stored copy has the link replaced: never resent
const KBF_MAIL_NO_RESEND_EVENTS = ['password_reset', 'password_reset_requested'];

// Turn an attachment entry into [absolute path, name], or null when the file
// is gone. Entries: an absolute path string, ['path' => absolute, 'name'],
// or ['file' => path relative to the site folder, 'name'].
function kbfMailAttachmentPath($attachment) {
    $path = null;
    $name = '';
    if (is_string($attachment)) {
        $path = $attachment;
    } elseif (is_array($attachment)) {
        $name = (string) ($attachment['name'] ?? '');
        if (!empty($attachment['file'])) {
            $relative = str_replace('\\', '/', (string) $attachment['file']);
            if (strpos($relative, '..') !== false) {
                return null;
            }
            $path = dirname(__DIR__) . '/' . ltrim($relative, '/');
        } elseif (!empty($attachment['path'])) {
            $path = (string) $attachment['path'];
        }
    }
    if ($path === null || !is_file($path)) {
        return null;
    }
    return [$path, $name];
}

// Holds a subject and bodies while they are being built. It never sends
// anything itself.
class KbfEmailDraft {
    public $Subject = '';
    public $Body = '';
    public $AltBody = '';
}

// Why the last email ended as it did: 'sent', 'allowance', 'paused', 'lock',
// 'ratelimit', 'temporary', 'permanent' or 'database'
function kbfMailLastOutcome($set = null) {
    static $last = '';
    if ($set !== null) {
        $last = (string) $set;
    }
    return $last;
}

// Reason for the last 'waiting' or 'failed' result in this request
function kbfMailLastError($set = null) {
    static $last = '';
    if ($set !== null) {
        $last = (string) $set;
    }
    return $last;
}

// Replace every secret (key) with its stand-in (value)
function kbfMailRedact($text, array $redact) {
    if ($text === null || $text === '' || empty($redact)) {
        return $text;
    }
    return str_replace(array_keys($redact), array_values($redact), (string) $text);
}

function kbfMailCut($text, $max) {
    $text = (string) $text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    return substr($text, 0, $max);
}

// ============================================
// SETTINGS AND ALLOWANCE
// ============================================
function kbfSetSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("
        INSERT INTO Settings (SettingKey, SettingValue) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
    ");
    $stmt->execute([$key, (string) $value]);
    kbfMailSettings($pdo, true);
}

// A pause time from a setting, or null when it is not in the future
function kbfMailPausedValue($pdo, $key) {
    $raw = trim((string) getSetting($pdo, $key, ''));
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts !== false && $ts > time()) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return null;
}

// ['limit', 'reserve' (important), 'reserveNormal', 'bulkShare',
//  'pausedUntil', 'bulkPausedUntil' ('Y-m-d H:i:s' or null)]
// Read once and kept for 30 seconds in this request (a long job run reads
// them again now and then); $reset reads them again at once.
function kbfMailSettings($pdo, $reset = false) {
    static $cached = null;
    static $readAt = 0;
    if ($reset) {
        $cached = null;
        return null;
    }
    if ($cached !== null && time() - $readAt < 30) {
        return $cached;
    }
    $limit = (int) getSetting($pdo, 'email_daily_limit', KBF_MAIL_DEFAULT_LIMIT);
    if ($limit < 1) {
        $limit = KBF_MAIL_DEFAULT_LIMIT;
    }
    $reserve = (int) getSetting($pdo, 'email_reserve_important', KBF_MAIL_DEFAULT_RESERVE);
    $reserve = max(0, min($reserve, intdiv($limit, 2)));
    $reserveNormal = (int) getSetting($pdo, 'email_reserve_normal', KBF_MAIL_DEFAULT_RESERVE_NORMAL);
    $reserveNormal = max(0, min($reserveNormal, $limit - $reserve));

    $cached = [
        'limit' => $limit,
        'reserve' => $reserve,
        'reserveNormal' => $reserveNormal,
        'bulkShare' => max(0, $limit - $reserve - $reserveNormal),
        'pausedUntil' => kbfMailPausedValue($pdo, 'email_paused_until'),
        'bulkPausedUntil' => kbfMailPausedValue($pdo, 'email_bulk_paused_until'),
    ];
    $readAt = time();
    return $cached;
}

// The separate service for broadcasts, or null when it is not set up.
// ['host', 'port', 'username', 'password', 'from', 'limit']
function kbfMailBulkService() {
    static $service = false;
    if ($service !== false) {
        return $service;
    }
    $service = null;
    $keys = ['BULK_SMTP_HOST', 'BULK_SMTP_PORT', 'BULK_SMTP_USERNAME', 'BULK_SMTP_PASSWORD', 'BULK_DAILY_LIMIT'];
    $values = [];
    foreach ($keys as $key) {
        $values[$key] = trim((string) getenv($key));
    }
    $set = array_filter($values, function ($v) { return $v !== ''; });
    if (!$set) {
        return $service;
    }
    $port = (int) $values['BULK_SMTP_PORT'];
    $limit = (int) $values['BULK_DAILY_LIMIT'];
    if (count($set) < count($keys) || $port < 1 || $limit < 1) {
        if (function_exists('kbfLogOnce')) {
            kbfLogOnce('The broadcast email service in .env is incomplete (BULK_SMTP_HOST, BULK_SMTP_PORT, BULK_SMTP_USERNAME, BULK_SMTP_PASSWORD and BULK_DAILY_LIMIT are all needed); broadcasts use the Hostinger mailbox.');
        }
        return $service;
    }
    $from = trim((string) getenv('BULK_SMTP_FROM'));
    $service = [
        'host' => $values['BULK_SMTP_HOST'],
        'port' => $port,
        'username' => $values['BULK_SMTP_USERNAME'],
        'password' => $values['BULK_SMTP_PASSWORD'],
        'from' => filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : (getenv('SMTP_USERNAME') ?: 'noreply@kbf-uk.org'),
        'limit' => $limit,
    ];
    return $service;
}

// Sent emails in the last $hours hours.
// $scope: 'all', 'main' (what counts against the Hostinger allowance) or
// 'bulk' (bulk emails only)
function kbfMailSentLast($pdo, $scope = 'all', $hours = 24) {
    $where = "Status = 'sent' AND SentDate > DATE_SUB(NOW(), INTERVAL " . (int) $hours . " HOUR)";
    if ($scope === 'bulk') {
        $where .= " AND Priority = 'bulk'";
    } elseif ($scope === 'main' && kbfMailBulkService() !== null) {
        $where .= " AND Priority <> 'bulk'";
    }
    return (int) $pdo->query("SELECT COUNT(*) FROM EmailLog WHERE $where")->fetchColumn();
}

// Sent emails that count against the Hostinger allowance (last 24 hours)
function kbfMailSentLast24h($pdo) {
    return kbfMailSentLast($pdo, 'main', 24);
}

// Important and normal emails still waiting (and not being skipped for now)
function kbfMailUrgentWaiting($pdo) {
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM EmailLog
        WHERE Status = 'waiting' AND Priority IN ('important', 'normal')
        " . (kbfMailHasRetryColumn($pdo) ? "AND (NextAttemptDate IS NULL OR NextAttemptDate <= NOW())" : "") . "
    ");
    return (int) $stmt->fetchColumn();
}

// Most emails of this priority that may be sent in 24 hours (Hostinger)
function kbfMailCap($priority, $limit, $reserve, $reserveNormal = 0) {
    if ($priority === 'important') {
        return $limit;
    }
    if ($priority === 'normal') {
        return max(0, $limit - $reserve);
    }
    return max(0, $limit - $reserve - $reserveNormal);
}

// Most bulk emails per hour: the day's share spread evenly (at least 1)
function kbfMailBulkPerHour($share) {
    return $share > 0 ? max(1, (int) ceil($share / 24)) : 0;
}

// How many emails of this priority may go out right now (0 when paused)
function kbfMailRemaining($pdo, $priority) {
    $settings = kbfMailSettings($pdo);
    $service = $priority === 'bulk' ? kbfMailBulkService() : null;

    // Bulk through the separate service: its own limit and its own pause
    if ($service !== null) {
        if ($settings['bulkPausedUntil'] !== null) {
            return 0;
        }
        $day = $service['limit'] - kbfMailSentLast($pdo, 'bulk', 24);
        $hour = kbfMailBulkPerHour($service['limit']) - kbfMailSentLast($pdo, 'bulk', 1);
        return max(0, min($day, $hour));
    }

    if ($settings['pausedUntil'] !== null) {
        return 0;
    }
    if ($priority === 'bulk') {
        if (kbfMailUrgentWaiting($pdo) > 0) {
            return 0;
        }
        $day = $settings['bulkShare'] - kbfMailSentLast24h($pdo);
        $hour = kbfMailBulkPerHour($settings['bulkShare']) - kbfMailSentLast($pdo, 'bulk', 1);
        return max(0, min($day, $hour));
    }
    $cap = kbfMailCap($priority, $settings['limit'], $settings['reserve'], $settings['reserveNormal']);
    return max(0, $cap - kbfMailSentLast24h($pdo));
}

// Pause sending for an hour (the mail server's own rate limit). $bulk: the
// separate broadcast service only.
function kbfMailPause($pdo, $bulk = false) {
    $key = $bulk ? 'email_bulk_paused_until' : 'email_paused_until';
    try {
        kbfSetSetting($pdo, $key, date('Y-m-d H:i:s', time() + KBF_MAIL_PAUSE_SECONDS));
    } catch (PDOException $e) {
        error_log('mailer: could not set ' . $key . ': ' . $e->getMessage());
    }
}

// True when EmailLog.NextAttemptDate exists (database/schema-fixes.sql)
function kbfMailHasRetryColumn($pdo) {
    static $has = null;
    if ($has === null) {
        try {
            $has = (int) $pdo->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailLog' AND COLUMN_NAME = 'NextAttemptDate'
            ")->fetchColumn() > 0;
        } catch (PDOException $e) {
            $has = false;
        }
    }
    return $has;
}

// ============================================
// SMTP (the only SMTP set-up on the site)
// Returns ['kind' => 'sent'|'ratelimit'|'permanent'|'temporary', 'error' => string]
// $msg: toEmail, toName, subject, html, text, attachments
// ============================================
// $service: null for the Hostinger mailbox (.env SMTP_*), or the separate
// broadcast service from kbfMailBulkService()
function kbfMailSmtpSend(array $msg, $service = null) {
    $host = getenv('SMTP_HOST') ?: 'smtp.hostinger.com';
    $port = (int) (getenv('SMTP_PORT') ?: 465);
    $account = getenv('SMTP_USERNAME') ?: 'noreply@kbf-uk.org';
    $login = $account;
    $password = getenv('SMTP_PASSWORD');
    $from = $account;
    if (is_array($service)) {
        $host = $service['host'];
        $port = (int) $service['port'];
        $login = $service['username'];
        $password = $service['password'];
        $from = $service['from'];
    }

    $mail = new PHPMailer(true);

    // 1. Build the message. A problem here is a real failure.
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $login;
        $mail->Password   = $password;
        $mail->SMTPSecure = $port === 587 ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 20;

        $mail->setFrom($from, 'KBF-UK');
        $mail->addReplyTo($account, 'KBF-UK');
        $mail->addAddress((string) $msg['toEmail'], (string) ($msg['toName'] ?? ''));

        foreach (($msg['attachments'] ?? []) as $attachment) {
            $resolved = kbfMailAttachmentPath($attachment);
            if ($resolved === null) {
                // A removed file never stops the email itself
                error_log('mailer: attachment file missing, sent without it');
                continue;
            }
            $mail->addAttachment($resolved[0], $resolved[1]);
        }

        $mail->isHTML(true);
        $mail->Subject = (string) $msg['subject'];
        $mail->Body    = (string) $msg['html'];
        $mail->AltBody = (string) ($msg['text'] ?? '');

        $mail->preSend();
    } catch (PHPMailerException $e) {
        return ['kind' => 'permanent', 'error' => 'The message could not be built: ' . $e->getMessage()];
    } catch (\Throwable $e) {
        return ['kind' => 'permanent', 'error' => 'The message could not be built: ' . $e->getMessage()];
    }

    // 2. Hand it to the mail server
    try {
        $mail->postSend();
        return ['kind' => 'sent', 'error' => ''];
    } catch (\Throwable $e) {
        $text = trim($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage());
        $code = 0;
        $smtp = $mail->getSMTPInstance();
        if ($smtp) {
            $last = $smtp->getError();
            $code = (int) ($last['smtp_code'] ?? 0);
            if (!empty($last['detail']) && strpos($text, (string) $last['detail']) === false) {
                $text .= ' ' . $last['detail'];
            }
        }
        if ($code === 0 && preg_match('/SMTP code:\s*(\d{3})/i', $text, $m)) {
            $code = (int) $m[1];
        }
        if ($code === 0 && preg_match('/\b([45]\d\d)[ -]/', $text, $m)) {
            $code = (int) $m[1];
        }

        return kbfMailClassifyFailure($code, $text);
    }
}

// Sorts a refusal from the mail server ($code: SMTP reply code, 0 when
// unknown; $text: the error). Returns ['kind', 'error'] where kind is
//   'permanent'  never retried: 5xx replies and addresses that can never
//                receive mail (no such user, unknown, invalid or reserved
//                domain, mailbox does not exist, recipient rejected)
//   'ratelimit'  the mail server's own rate limit
//   'temporary'  4xx replies, timeouts, connection and sign-in problems
// A permanent error starts with a plain-English reason for the email log,
// followed by the mail server's own words.
function kbfMailClassifyFailure($code, $text) {
    $code = (int) $code;
    $text = trim((string) $text);
    $isTemporaryCode = $code >= 400 && $code < 500;

    // The address can never receive mail (unless the server says "try later")
    if (!$isTemporaryCode && preg_match('/no such (user|mailbox|recipient|domain)|user (unknown|not found|does not exist)|unknown (user|recipient|mailbox|domain|host)|'
        . 'mailbox (does not exist|not found|unavailable|is unavailable)|does not exist|invalid (recipient|address|domain|mailbox)|'
        . 'domain (not found|does not exist|is reserved|name not found)|reserved (domain|for documentation)|rfc ?2606|host not found|no mx|'
        . 'recipient (address )?(rejected|refused)|address rejected|recipients? failed|undeliverable address|'
        . 'cannot receive (e-?)?mail|can\'t receive (e-?)?mail|\b5\.1\.[0-3]\b/i', $text)) {
        return ['kind' => 'permanent', 'error' => 'The address cannot receive email. Mail server: ' . $text];
    }
    if ($code === 451 || preg_match('/rate.?limit|too many (messages|emails|mails|recipients)|sending limit|quota|limit exceeded|exceeded/i', $text)) {
        return ['kind' => 'ratelimit', 'error' => $text];
    }
    if ($isTemporaryCode) {
        return ['kind' => 'temporary', 'error' => $text];
    }
    // Our own connection or sign-in problems are never the email's fault,
    // even when the reply is a 5xx (e.g. 535 for a wrong password)
    if (preg_match('/could not connect|connection|authenticat|timed out|timeout|helo|ehlo|starttls/i', $text)) {
        return ['kind' => 'temporary', 'error' => $text];
    }
    if ($code >= 500 && $code < 600) {
        return ['kind' => 'permanent', 'error' => 'The mail server refused this email permanently. Mail server: ' . $text];
    }
    return ['kind' => 'temporary', 'error' => $text];
}

// ============================================
// DELIVERY OF ONE EmailLog ROW
// $live: the message to send (with secrets). $hasSecrets: never leave it
// waiting, because the stored copy has the secrets replaced.
// $logId 0: the EmailLog row could not be written (table missing), so the
// email is sent straight away without the allowance check.
// ============================================
function kbfMailDeliver($pdo, $logId, $priority, array $live, $hasSecrets, array $redact) {
    $finish = function ($status, $error = null, $sent = false, $attempted = false) use ($pdo, $logId, $redact) {
        if ($logId) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE EmailLog
                    SET Status = ?, LastError = ?, SentDate = " . ($sent ? "NOW()" : "SentDate") . ",
                        Attempts = Attempts + ?
                    WHERE Id = ?
                ");
                $stmt->execute([
                    $status,
                    $error === null ? null : kbfMailCut(kbfMailRedact($error, $redact), 500),
                    $attempted ? 1 : 0,
                    $logId,
                ]);
            } catch (PDOException $e) {
                error_log('mailer: could not update EmailLog ' . (int) $logId . ': ' . $e->getMessage());
            }
        }
        kbfMailLastError($error === null ? '' : kbfMailRedact($error, $redact));
        return $status;
    };

    // Without a log row there is no allowance to check: just send
    if (!$logId) {
        $result = kbfMailSmtpSend($live);
        kbfMailLastOutcome($result['kind']);
        if ($result['kind'] === 'sent') {
            return $finish('sent');
        }
        error_log('mailer: send without log failed: ' . kbfMailRedact($result['error'], $redact));
        return $finish('failed', $result['error']);
    }

    $waitOrFail = function ($reason, $kind) use ($finish, $hasSecrets) {
        kbfMailLastOutcome($kind);
        if ($hasSecrets) {
            return $finish('failed', $reason . ' Reset links are never stored, so a new reset must be requested.');
        }
        return $finish('waiting', $reason);
    };

    // One send at a time across the site, so the allowance cannot be overrun
    $locked = false;
    try {
        $locked = (int) $pdo->query("SELECT GET_LOCK('" . KBF_MAIL_LOCK_NAME . "', " . KBF_MAIL_LOCK_WAIT . ")")->fetchColumn() === 1;
    } catch (PDOException $e) {
        error_log('mailer: GET_LOCK failed: ' . $e->getMessage());
    }
    if (!$locked) {
        return $waitOrFail('Another email was being sent. It will go out on the next run.', 'lock');
    }

    try {
        // Claim: the row must still be waiting (another run or a Resend may
        // have sent it meanwhile); never send the same email twice
        $stmt = $pdo->prepare("SELECT Status FROM EmailLog WHERE Id = ?");
        $stmt->execute([$logId]);
        $current = (string) $stmt->fetchColumn();
        if ($current !== 'waiting') {
            kbfMailLastOutcome('done');
            return in_array($current, ['sent', 'failed'], true) ? $current : 'failed';
        }

        // Bulk emails go through the separate broadcast service when it is set up
        $service = $priority === 'bulk' ? kbfMailBulkService() : null;
        $settings = kbfMailSettings($pdo);
        $pausedUntil = $service !== null ? $settings['bulkPausedUntil'] : $settings['pausedUntil'];
        if ($pausedUntil !== null) {
            return $waitOrFail('Sending is paused until ' . $pausedUntil . '.', 'paused');
        }
        if (kbfMailRemaining($pdo, $priority) < 1) {
            return $waitOrFail('The daily email allowance is used up.', 'allowance');
        }

        $result = kbfMailSmtpSend($live, $service);
        kbfMailLastOutcome($result['kind']);

        if ($result['kind'] === 'sent') {
            return $finish('sent', null, true, true);
        }
        if ($result['kind'] === 'ratelimit') {
            kbfMailPause($pdo, $service !== null);
            if ($hasSecrets) {
                return $finish('failed', 'The mail server refused because of its rate limit: ' . $result['error'] . ' Reset links are never stored, so a new reset must be requested.', false, true);
            }
            return $finish('waiting', 'The mail server refused because of its rate limit: ' . $result['error'], false, true);
        }
        if ($result['kind'] === 'temporary') {
            if ($hasSecrets) {
                return $finish('failed', $result['error'], false, true);
            }
            $status = $finish('waiting', $result['error'], false, true);
            // After KBF_MAIL_TEMP_SKIP_AFTER temporary failures this email is
            // skipped for a while (15, 30, 60 ... up to 360 minutes), so the
            // others carry on
            if (kbfMailHasRetryColumn($pdo)) {
                $stmt = $pdo->prepare("
                    UPDATE EmailLog
                    SET NextAttemptDate = DATE_ADD(NOW(), INTERVAL LEAST(?, 15 * POW(2, GREATEST(0, Attempts - ?))) MINUTE)
                    WHERE Id = ? AND Status = 'waiting' AND Attempts >= ?
                ");
                $stmt->execute([KBF_MAIL_TEMP_MAX_BACKOFF, KBF_MAIL_TEMP_SKIP_AFTER, $logId, KBF_MAIL_TEMP_SKIP_AFTER]);
                if ($stmt->rowCount() > 0) {
                    kbfMailLastOutcome('skipped');
                }
            }
            return $status;
        }

        // Permanent refusal: failed at once, never retried, so the email is
        // offered to the mail server (and counted by it) only this once
        return $finish('failed', $result['error'], false, true);
    } catch (PDOException $e) {
        error_log('mailer: delivery error for EmailLog ' . (int) $logId . ': ' . $e->getMessage());
        return $waitOrFail('A database problem stopped the email. It will be tried again.', 'database');
    } finally {
        try {
            $pdo->query("SELECT RELEASE_LOCK('" . KBF_MAIL_LOCK_NAME . "')");
        } catch (PDOException $e) {
            // ignore
        }
    }
}

// ============================================
// THE SHARED SENDER
// $m: eventKey, priority, toEmail, toName, subject, html, text,
//     attachments (optional: ['file' => path relative to the site folder,
//                  'name' => ...], or ['path' => absolute, 'name' => ...]),
//     broadcastId, relatedType, relatedId (optional),
//     redact (optional: [secret => stand-in], applied before storing)
// Returns 'sent', 'waiting' or 'failed'. kbfMailLastError() gives the reason.
// The attachment list is stored in EmailLog.AttachmentsJson, so a waiting
// email is sent later with the same files.
// ============================================
function kbfSendEmail(array $m) {
    global $pdo;

    $priority = in_array($m['priority'] ?? '', KBF_MAIL_PRIORITIES, true) ? $m['priority'] : 'normal';
    $redact = (isset($m['redact']) && is_array($m['redact'])) ? array_filter($m['redact'], function ($v, $k) {
        return $k !== '' && $k !== null;
    }, ARRAY_FILTER_USE_BOTH) : [];
    $hasSecrets = !empty($redact);

    $live = [
        'toEmail' => trim((string) ($m['toEmail'] ?? '')),
        'toName' => (string) ($m['toName'] ?? ''),
        'subject' => (string) ($m['subject'] ?? ''),
        'html' => (string) ($m['html'] ?? ''),
        'text' => (string) ($m['text'] ?? ''),
        'attachments' => is_array($m['attachments'] ?? null) ? $m['attachments'] : [],
    ];

    // Attachment list kept with the log row (relative 'file' or absolute 'path')
    $stored = [];
    foreach ($live['attachments'] as $attachment) {
        if (is_array($attachment) && (!empty($attachment['file']) || !empty($attachment['path']))) {
            $stored[] = array_filter([
                'file' => $attachment['file'] ?? null,
                'path' => $attachment['path'] ?? null,
                'name' => isset($attachment['name']) ? (string) $attachment['name'] : null,
            ], function ($v) { return $v !== null; });
        } elseif (is_string($attachment) && $attachment !== '') {
            $stored[] = ['path' => $attachment];
        }
    }
    $attachmentsJson = $stored ? json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $params = [
        kbfMailCut((string) ($m['eventKey'] ?? 'other'), 60),
        $priority,
        kbfMailCut($live['toEmail'], 255),
        kbfMailCut($live['toName'], 255),
        kbfMailCut(kbfMailRedact($live['subject'], $redact), 255),
        kbfMailRedact($live['html'], $redact),
        kbfMailRedact($live['text'], $redact),
        isset($m['broadcastId']) ? (int) $m['broadcastId'] : null,
        isset($m['relatedType']) ? kbfMailCut((string) $m['relatedType'], 40) : null,
        isset($m['relatedId']) ? (int) $m['relatedId'] : null,
    ];

    $logId = 0;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO EmailLog
                (EventKey, Priority, ToEmail, ToName, Subject, BodyHtml, BodyText, Status, Attempts,
                 BroadcastId, RelatedType, RelatedId, AttachmentsJson, CreatedDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'waiting', 0, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute(array_merge($params, [$attachmentsJson]));
        $logId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        // database/notifications.sql not run yet (no AttachmentsJson column)
        try {
            $stmt = $pdo->prepare("
                INSERT INTO EmailLog
                    (EventKey, Priority, ToEmail, ToName, Subject, BodyHtml, BodyText, Status, Attempts,
                     BroadcastId, RelatedType, RelatedId, CreatedDate)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'waiting', 0, ?, ?, ?, NOW())
            ");
            $stmt->execute($params);
            $logId = (int) $pdo->lastInsertId();
        } catch (PDOException $e2) {
            // e.g. database/email-engine.sql not run yet: still send the email
            error_log('mailer: could not write EmailLog: ' . $e2->getMessage());
        }
    }

    return kbfMailDeliver($pdo, $logId, $priority, $live, $hasSecrets, $redact);
}

// Send an EmailLog row that is waiting (used by the job and Resend).
// Rows with secrets are never waiting, so the stored copy is complete.
// Attachments come back from AttachmentsJson.
function kbfMailSendWaitingRow($pdo, array $row) {
    $attachments = [];
    if (!empty($row['AttachmentsJson'])) {
        $decoded = json_decode((string) $row['AttachmentsJson'], true);
        if (is_array($decoded)) {
            $attachments = $decoded;
        }
    }
    $live = [
        'toEmail' => (string) $row['ToEmail'],
        'toName' => (string) ($row['ToName'] ?? ''),
        'subject' => (string) $row['Subject'],
        'html' => (string) ($row['BodyHtml'] ?? ''),
        'text' => (string) ($row['BodyText'] ?? ''),
        'attachments' => $attachments,
    ];
    return kbfMailDeliver($pdo, (int) $row['Id'], (string) $row['Priority'], $live, false, []);
}

// Readable sentence for an admin about an email result, e.g.
// kbfMailOutcomeText('waiting') => "The email will go out when the daily allowance frees up."
function kbfMailOutcomeText($status) {
    if ($status === 'sent') {
        return 'The email has been sent.';
    }
    if ($status === 'waiting') {
        return 'The email will go out when the daily allowance frees up.';
    }
    $reason = trim(kbfMailLastError());
    return 'The email could not be sent' . ($reason !== '' ? ': ' . rtrim($reason, '.') : '') . '.';
}
?>
