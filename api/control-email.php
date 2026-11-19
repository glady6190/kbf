<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/mailer.php';

// ============================================
// CONTROL CENTRE: EMAIL AND LIMITS (control.email)
//
//   GET  ?action=summary                       settings, usage in the last 24 hours, events
//   GET  ?action=log&status=&event=&offset=    email log, newest first, 50 per page
//   GET  ?action=csv&status=&event=            the same log as a CSV download
//   POST {action:'saveSettings', limit, reserve, reserveNormal, batchSize}
//   POST {action:'resend', id}                 a failed email goes back to waiting
//
// Order of checks: logged in (401), CSRF on writes (403), control.email (403).
// Writes are blocked while impersonating; reading is allowed.
// Email bodies are never returned. Password reset links are never stored.
// ============================================

const KBF_EMAIL_LOG_PAGE_SIZE = 50;
const KBF_EMAIL_CSV_MAX_ROWS = 10000;

$method = $_SERVER['REQUEST_METHOD'];

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}

if ($method === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}

requirePermission('control.email');

if ($method === 'POST') {
    requireNotImpersonating();
}

// ---------- helpers ----------

// Filters from the query string: [whereSql, params]
function emailLogFilters() {
    $where = [];
    $params = [];

    $status = $_GET['status'] ?? '';
    if (is_string($status) && in_array($status, ['sent', 'waiting', 'failed'], true)) {
        $where[] = 'Status = ?';
        $params[] = $status;
    }

    $event = $_GET['event'] ?? '';
    if (is_string($event) && array_key_exists($event, KBF_MAIL_EVENTS)) {
        $where[] = 'EventKey = ?';
        $params[] = $event;
    }

    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}

function emailEventName($key) {
    return KBF_MAIL_EVENTS[$key] ?? ucfirst(str_replace('_', ' ', (string) $key));
}

// A strict whole number from JSON input, or null
function readWholeNumber($value) {
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^\s*\d{1,6}\s*$/', $value)) {
        return (int) trim($value);
    }
    return null;
}

// Spreadsheet-safe CSV cell (stops formulas such as =HYPERLINK)
function csvCell($value) {
    $value = (string) $value;
    if ($value !== '' && strpos('=+-@' . "\t\r", $value[0]) !== false) {
        $value = "'" . $value;
    }
    return $value;
}

function emailSettingsNow($pdo) {
    $mail = kbfMailSettings($pdo);
    $batch = (int) getSetting($pdo, 'broadcast_batch_size', 20);
    if ($batch < 1) {
        $batch = 20;
    }
    return [
        'limit' => $mail['limit'],
        'reserve' => $mail['reserve'],
        'reserveNormal' => $mail['reserveNormal'],
        'bulkShare' => $mail['bulkShare'],
        'batchSize' => $batch,
        'pausedUntil' => $mail['pausedUntil'],
        'bulkPausedUntil' => $mail['bulkPausedUntil'],
    ];
}

// Which service broadcasts use (never the password)
function emailBroadcastService($pdo) {
    $service = kbfMailBulkService();
    if ($service === null) {
        return ['separate' => false, 'name' => 'Hostinger mailbox (shared with every other email)'];
    }
    return [
        'separate' => true,
        'name' => 'Separate broadcast service (' . $service['host'] . ')',
        'limit' => $service['limit'],
        'perHour' => kbfMailBulkPerHour($service['limit']),
        'sent24h' => kbfMailSentLast($pdo, 'bulk', 24),
    ];
}

// ============================================
// GET
// ============================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'summary';

    try {
        if ($action === 'summary') {
            $settings = emailSettingsNow($pdo);
            $sent24h = kbfMailSentLast24h($pdo);

            // Waiting rows only (uses the Status index instead of the whole log)
            $stmt = $pdo->query("
                SELECT
                    SUM(Priority IN ('important', 'normal')) AS WaitingUrgent,
                    SUM(Priority = 'bulk') AS WaitingBulk
                FROM EmailLog
                WHERE Status = 'waiting'
            ");
            $counts = $stmt->fetch() ?: [];

            $stmt = $pdo->query("
                SELECT COUNT(*)
                FROM EmailQueue eq
                JOIN EmailBroadcasts b ON b.BroadcastId = eq.BroadcastId
                WHERE eq.Status = 'pending' AND b.Status IN ('queued', 'sending')
            ");
            $broadcastPending = (int) $stmt->fetchColumn();

            $events = [];
            foreach (KBF_MAIL_EVENTS as $key => $name) {
                $events[] = ['key' => $key, 'name' => $name];
            }

            sendJSON([
                'success' => true,
                'settings' => [
                    'limit' => $settings['limit'],
                    'reserve' => $settings['reserve'],
                    'batchSize' => $settings['batchSize'],
                    'reserveNormal' => $settings['reserveNormal'],
                ],
                'usage' => [
                    'sent24h' => $sent24h,
                    'limit' => $settings['limit'],
                    'reserve' => $settings['reserve'],
                    'reserveNormal' => $settings['reserveNormal'],
                    'broadcastCap' => $settings['bulkShare'],
                    'broadcastPerHour' => kbfMailBulkPerHour($settings['bulkShare']),
                    'broadcastService' => emailBroadcastService($pdo),
                    'pausedUntil' => $settings['pausedUntil'],
                    'waiting' => (int) ($counts['WaitingUrgent'] ?? 0),
                    'broadcastPending' => $broadcastPending,
                ],
                'events' => $events,
                'canEdit' => !isImpersonating(),
            ]);
        }

        if ($action === 'log') {
            list($whereSql, $params) = emailLogFilters();
            $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($offset === false) {
                $offset = 0;
            }
            $take = KBF_EMAIL_LOG_PAGE_SIZE + 1; // one extra row tells us there is more

            $stmt = $pdo->prepare("
                SELECT Id, EventKey, Priority, ToEmail, ToName, Subject, Status, Attempts, LastError,
                       CreatedDate, SentDate
                FROM EmailLog
                $whereSql
                ORDER BY Id DESC
                LIMIT $take OFFSET " . (int) $offset . "
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $hasMore = count($rows) > KBF_EMAIL_LOG_PAGE_SIZE;
            if ($hasMore) {
                array_pop($rows);
            }

            $emails = array_map(function ($r) {
                return [
                    'id' => (int) $r['Id'],
                    'eventKey' => $r['EventKey'],
                    'eventName' => emailEventName($r['EventKey']),
                    'priority' => $r['Priority'],
                    'toEmail' => $r['ToEmail'],
                    'toName' => $r['ToName'],
                    'subject' => $r['Subject'],
                    'status' => $r['Status'],
                    'attempts' => (int) $r['Attempts'],
                    'lastError' => $r['LastError'],
                    'createdDate' => $r['CreatedDate'],
                    'sentDate' => $r['SentDate'],
                    'canResend' => $r['Status'] === 'failed' && !in_array($r['EventKey'], KBF_MAIL_NO_RESEND_EVENTS, true),
                ];
            }, $rows);

            sendJSON([
                'success' => true,
                'emails' => $emails,
                'hasMore' => $hasMore,
                'nextOffset' => (int) $offset + count($emails),
            ]);
        }

        if ($action === 'csv') {
            list($whereSql, $params) = emailLogFilters();
            $stmt = $pdo->prepare("
                SELECT Id, EventKey, Priority, ToEmail, ToName, Subject, Status, Attempts, LastError,
                       CreatedDate, SentDate
                FROM EmailLog
                $whereSql
                ORDER BY Id DESC
                LIMIT " . KBF_EMAIL_CSV_MAX_ROWS . "
            ");
            $stmt->execute($params);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="email_log_' . date('Y-m-d') . '.csv"');

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker for spreadsheet programs
            fputcsv($out, ['When', 'Email', 'Priority', 'To', 'Name', 'Subject', 'Result', 'Attempts', 'Sent', 'Last error']);
            while ($r = $stmt->fetch()) {
                fputcsv($out, array_map('csvCell', [
                    $r['CreatedDate'],
                    emailEventName($r['EventKey']),
                    $r['Priority'],
                    $r['ToEmail'],
                    $r['ToName'],
                    $r['Subject'],
                    ucfirst($r['Status']),
                    $r['Attempts'],
                    $r['SentDate'],
                    $r['LastError'],
                ]));
            }
            fclose($out);

            auditLog('email_log_exported', 'setting', null, 'Email log downloaded as CSV');
            exit;
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        error_log('control-email.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'The email log could not be read. Has database/email-engine.sql been run?'], 500);
    }
}

// ============================================
// POST
// ============================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        sendJSON(['success' => false, 'message' => 'Invalid request body'], 400);
    }
    kbfRequireScalarFields($data);
    $action = $data['action'] ?? '';

    try {
        // ---------- SAVE SETTINGS ----------
        if ($action === 'saveSettings') {
            $limit = readWholeNumber($data['limit'] ?? null);
            $reserve = readWholeNumber($data['reserve'] ?? null);
            $batch = readWholeNumber($data['batchSize'] ?? null);
            // Older pages do not send it: keep the saved value
            $reserveNormal = array_key_exists('reserveNormal', $data)
                ? readWholeNumber($data['reserveNormal'])
                : kbfMailSettings($pdo)['reserveNormal'];

            if ($limit === null || $limit < 1 || $limit > 10000) {
                sendJSON(['success' => false, 'message' => 'Emails per 24 hours must be a whole number from 1 to 10,000.', 'field' => 'limit'], 400);
            }
            $maxReserve = intdiv($limit, 2);
            if ($reserve === null || $reserve < 0 || $reserve > $maxReserve) {
                sendJSON(['success' => false, 'message' => 'Keep back for important emails must be from 0 to ' . $maxReserve . ' (half the limit).', 'field' => 'reserve'], 400);
            }
            $maxReserveNormal = $limit - $reserve;
            if ($reserveNormal === null || $reserveNormal < 0 || $reserveNormal > $maxReserveNormal) {
                sendJSON(['success' => false, 'message' => 'Keep back for everyday emails must be from 0 to ' . $maxReserveNormal . ' (the limit minus the important reserve).', 'field' => 'reserveNormal'], 400);
            }
            if ($batch === null || $batch < 1 || $batch > $limit) {
                sendJSON(['success' => false, 'message' => 'Broadcast emails per run must be from 1 to ' . $limit . ' (the limit).', 'field' => 'batchSize'], 400);
            }

            $before = [
                'email_daily_limit' => (string) getSetting($pdo, 'email_daily_limit', ''),
                'email_reserve_important' => (string) getSetting($pdo, 'email_reserve_important', ''),
                'email_reserve_normal' => (string) getSetting($pdo, 'email_reserve_normal', ''),
                'broadcast_batch_size' => (string) getSetting($pdo, 'broadcast_batch_size', ''),
            ];
            $after = [
                'email_daily_limit' => (string) $limit,
                'email_reserve_important' => (string) $reserve,
                'email_reserve_normal' => (string) $reserveNormal,
                'broadcast_batch_size' => (string) $batch,
            ];

            $pdo->beginTransaction();
            foreach ($after as $key => $value) {
                if ($before[$key] !== $value) {
                    kbfSetSetting($pdo, $key, $value);
                }
            }
            $pdo->commit();

            $changed = 0;
            foreach ($after as $key => $value) {
                if ($before[$key] !== $value) {
                    $changed++;
                    auditLog('setting_changed', 'setting', null,
                        $key . ': ' . ($before[$key] === '' ? '(not set)' : $before[$key]) . ' -> ' . $value);
                }
            }

            $settings = emailSettingsNow($pdo);
            sendJSON([
                'success' => true,
                'message' => $changed ? 'Changes saved' : 'Nothing to change',
                'settings' => [
                    'limit' => $settings['limit'],
                    'reserve' => $settings['reserve'],
                    'reserveNormal' => $settings['reserveNormal'],
                    'batchSize' => $settings['batchSize'],
                ],
            ]);
        }

        // ---------- RESEND A FAILED EMAIL ----------
        if ($action === 'resend') {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                sendJSON(['success' => false, 'message' => 'Invalid email'], 400);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT Id, EventKey, Status, ToEmail, BroadcastId, RelatedType, RelatedId
                FROM EmailLog WHERE Id = ? FOR UPDATE
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Email not found'], 404);
            }
            if ($row['Status'] !== 'failed') {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Only failed emails can be resent'], 400);
            }
            if (in_array($row['EventKey'], KBF_MAIL_NO_RESEND_EVENTS, true)) {
                // The stored copy has no link, so it cannot be sent again
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Password reset emails cannot be resent. Ask the person to request a new reset.'], 400);
            }

            $stmt = $pdo->prepare("UPDATE EmailLog SET Status = 'waiting', Attempts = 0, LastError = NULL"
                . (kbfMailHasRetryColumn($pdo) ? ", NextAttemptDate = NULL" : "") . " WHERE Id = ?");
            $stmt->execute([$id]);

            // A broadcast recipient goes back on the broadcast's list as well
            if ($row['RelatedType'] === 'broadcast_recipient' && $row['RelatedId'] && $row['BroadcastId']) {
                $stmt = $pdo->prepare("
                    UPDATE EmailQueue SET Status = 'pending', LastError = NULL
                    WHERE QueueId = ? AND BroadcastId = ? AND Status = 'failed'
                ");
                $stmt->execute([(int) $row['RelatedId'], (int) $row['BroadcastId']]);
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE EmailBroadcasts
                        SET FailedCount = GREATEST(FailedCount - 1, 0),
                            Status = IF(Status = 'completed', 'sending', Status),
                            CompletedDate = IF(Status = 'sending', NULL, CompletedDate)
                        WHERE BroadcastId = ?
                    ");
                    $stmt->execute([(int) $row['BroadcastId']]);
                }
            }

            $pdo->commit();

            auditLog('email_resend', 'email', $id,
                'Email ' . emailEventName($row['EventKey']) . ' set back to waiting (was failed)');

            sendJSON(['success' => true, 'message' => 'The email is waiting and will be sent on the next run']);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('control-email.php POST: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
?>
