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
// Daily allowance = EmailLog rows with Status 'sent' in the last 24 hours:
//   important  may use the whole email_daily_limit
//   normal     may use the limit minus email_reserve_important
//   bulk       may use the limit minus the reserve, and only when no
//              important or normal emails are waiting
// Nothing is sent while email_paused_until is in the future.
//
// Results: 'sent', 'waiting' (the job sends it later) or 'failed'.
//   * The mail server's own rate limit (e.g. SMTP 451, "ratelimit") keeps the
//     email waiting and pauses sending for one hour.
//   * Only real failures (invalid address, refused recipient, a message that
//     cannot be built) become failed, after KBF_MAIL_MAX_ATTEMPTS attempts.
//   * Connection or sign-in problems keep the email waiting.
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
const KBF_MAIL_MAX_ATTEMPTS = 3;
const KBF_MAIL_PAUSE_SECONDS = 3600;          // after the mail server's rate limit
const KBF_MAIL_LOCK_NAME = 'kbf_mailer_send';  // MySQL named lock: one send at a time
const KBF_MAIL_LOCK_WAIT = 10;                 // seconds to wait for the lock
const KBF_MAIL_DEFAULT_LIMIT = 100;
const KBF_MAIL_DEFAULT_RESERVE = 20;

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
}

// ['limit' => int, 'reserve' => int, 'pausedUntil' => 'Y-m-d H:i:s' or null]
function kbfMailSettings($pdo) {
    $limit = (int) getSetting($pdo, 'email_daily_limit', KBF_MAIL_DEFAULT_LIMIT);
    if ($limit < 1) {
        $limit = KBF_MAIL_DEFAULT_LIMIT;
    }
    $reserve = (int) getSetting($pdo, 'email_reserve_important', KBF_MAIL_DEFAULT_RESERVE);
    $reserve = max(0, min($reserve, intdiv($limit, 2)));

    $pausedUntil = null;
    $raw = trim((string) getSetting($pdo, 'email_paused_until', ''));
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts !== false && $ts > time()) {
            $pausedUntil = date('Y-m-d H:i:s', $ts);
        }
    }

    return ['limit' => $limit, 'reserve' => $reserve, 'pausedUntil' => $pausedUntil];
}

function kbfMailSentLast24h($pdo) {
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM EmailLog
        WHERE Status = 'sent' AND SentDate > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    return (int) $stmt->fetchColumn();
}

// Important and normal emails still waiting
function kbfMailUrgentWaiting($pdo) {
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM EmailLog
        WHERE Status = 'waiting' AND Priority IN ('important', 'normal')
    ");
    return (int) $stmt->fetchColumn();
}

// Most emails of this priority that may be sent in 24 hours
function kbfMailCap($priority, $limit, $reserve) {
    return $priority === 'important' ? $limit : max(0, $limit - $reserve);
}

// How many emails of this priority may go out right now (0 when paused)
function kbfMailRemaining($pdo, $priority) {
    $settings = kbfMailSettings($pdo);
    if ($settings['pausedUntil'] !== null) {
        return 0;
    }
    if ($priority === 'bulk' && kbfMailUrgentWaiting($pdo) > 0) {
        return 0;
    }
    $cap = kbfMailCap($priority, $settings['limit'], $settings['reserve']);
    return max(0, $cap - kbfMailSentLast24h($pdo));
}

// Pause all sending for an hour (the mail server's own rate limit)
function kbfMailPause($pdo) {
    try {
        kbfSetSetting($pdo, 'email_paused_until', date('Y-m-d H:i:s', time() + KBF_MAIL_PAUSE_SECONDS));
    } catch (PDOException $e) {
        error_log('mailer: could not set email_paused_until: ' . $e->getMessage());
    }
}

// ============================================
// SMTP (the only SMTP set-up on the site)
// Returns ['kind' => 'sent'|'ratelimit'|'permanent'|'temporary', 'error' => string]
// $msg: toEmail, toName, subject, html, text, attachments
// ============================================
function kbfMailSmtpSend(array $msg) {
    $host = getenv('SMTP_HOST') ?: 'smtp.hostinger.com';
    $port = (int) (getenv('SMTP_PORT') ?: 465);
    $account = getenv('SMTP_USERNAME') ?: 'noreply@kbf-uk.org';

    $mail = new PHPMailer(true);

    // 1. Build the message. A problem here is a real failure.
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $account;
        $mail->Password   = getenv('SMTP_PASSWORD');
        $mail->SMTPSecure = $port === 587 ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 20;

        $mail->setFrom($account, 'KBF-UK');
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

        if ($code === 451 || preg_match('/rate.?limit|too many (messages|emails|mails|recipients)|sending limit|quota|limit exceeded|exceeded/i', $text)) {
            return ['kind' => 'ratelimit', 'error' => $text];
        }
        if (preg_match('/could not connect|connection|authenticate|timed out|timeout|helo|ehlo|starttls/i', $text)) {
            return ['kind' => 'temporary', 'error' => $text];
        }
        if (($code >= 500 && $code < 600) || preg_match('/recipients? failed|invalid address|recipient address rejected|user unknown|mailbox unavailable|no such user/i', $text)) {
            return ['kind' => 'permanent', 'error' => $text];
        }
        return ['kind' => 'temporary', 'error' => $text];
    }
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
        $settings = kbfMailSettings($pdo);
        if ($settings['pausedUntil'] !== null) {
            return $waitOrFail('Sending is paused until ' . $settings['pausedUntil'] . '.', 'paused');
        }
        if (kbfMailRemaining($pdo, $priority) < 1) {
            return $waitOrFail('The daily email allowance is used up.', 'allowance');
        }

        $result = kbfMailSmtpSend($live);
        kbfMailLastOutcome($result['kind']);

        if ($result['kind'] === 'sent') {
            return $finish('sent', null, true, true);
        }
        if ($result['kind'] === 'ratelimit') {
            kbfMailPause($pdo);
            if ($hasSecrets) {
                return $finish('failed', 'The mail server refused because of its rate limit: ' . $result['error'] . ' Reset links are never stored, so a new reset must be requested.', false, true);
            }
            return $finish('waiting', 'The mail server refused because of its rate limit: ' . $result['error'], false, true);
        }
        if ($result['kind'] === 'temporary') {
            if ($hasSecrets) {
                return $finish('failed', $result['error'], false, true);
            }
            return $finish('waiting', $result['error'], false, true);
        }

        // Real failure: failed after KBF_MAIL_MAX_ATTEMPTS attempts
        $attempts = 0;
        try {
            $stmt = $pdo->prepare("SELECT Attempts FROM EmailLog WHERE Id = ?");
            $stmt->execute([$logId]);
            $attempts = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $attempts = KBF_MAIL_MAX_ATTEMPTS;
        }
        if ($hasSecrets || $attempts + 1 >= KBF_MAIL_MAX_ATTEMPTS) {
            return $finish('failed', $result['error'], false, true);
        }
        return $finish('waiting', $result['error'], false, true);
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
