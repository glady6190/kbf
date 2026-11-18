<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/email-templates.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/broadcast-helpers.php';
require_once __DIR__ . '/auth/email-config.php';

// ============================================
// EMAIL BROADCASTS (content.broadcasts)
//
//   GET  ?action=list                 broadcasts (newest first) and page data
//   GET  ?action=count&target=&grades= recipients, unsubscribed, per day, days
//   GET  ?action=get&id=              a draft or scheduled broadcast to edit
//   POST action=preview               the email as the signed-in person sees it
//   POST action=test                  send it to the signed-in person
//   POST action=saveDraft [id]        save or update a draft
//   POST action=schedule [id] scheduledFor   (UK time, 10 minutes to 60 days ahead)
//   POST action=sendNow [id]          queue every recipient now
//   POST action=pause | resume | cancel | duplicate, id
//
// Content (saveDraft, schedule, sendNow, preview, test), as a form (may carry
// an attachment) or JSON: subject, bodyHtml, targetType (all|grades|staff),
// targetGrades (comma-separated ids), attachment + attachmentMode
// (link|attach), removeAttachment (1 drops a file attached to every email).
//
// Order of checks: logged in (401), CSRF on writes (403), content.broadcasts
// (403). Every change is blocked while impersonating (preview only reads).
// Every action is written to AuditLog.
// ============================================

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}
requirePermission('content.broadcasts');

const KBF_BROADCAST_LIST_LIMIT = 50;

// ============================================
// HELPERS
// ============================================
function bFail($message, $status = 400, $field = null) {
    $out = ['success' => false, 'message' => $message];
    if ($field !== null) {
        $out['field'] = $field;
    }
    sendJSON($out, $status);
}

function bNeedSchema($pdo) {
    if (!kbfBroadcastSchemaReady($pdo)) {
        bFail('Run database/grading-broadcasts.sql first.', 500);
    }
}

// Stored status -> page status ('queued' from the old page is sending)
function bStatus($raw) {
    $s = strtolower(trim((string) $raw));
    if ($s === 'queued') {
        return 'sending';
    }
    return $s;
}

function bPlain($value) {
    return trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
}

// [CategoryId => ['short' => ..., 'name' => ..., 'active' => bool]] for every grade
function bAllGrades($pdo) {
    static $grades = null;
    if ($grades === null) {
        $grades = [];
        $active = [];
        foreach (kbfActiveGrades($pdo) as $g) {
            $active[(int) $g['CategoryId']] = true;
        }
        $stmt = $pdo->query("SELECT CategoryId, ShortName, Name FROM Categories ORDER BY DisplayOrder ASC, CategoryId ASC");
        foreach ($stmt->fetchAll() as $g) {
            $grades[(int) $g['CategoryId']] = [
                'short' => bPlain($g['ShortName']),
                'name' => bPlain($g['Name']),
                'active' => isset($active[(int) $g['CategoryId']]),
            ];
        }
    }
    return $grades;
}

// "Everyone", "Staff only", "Grade C" or "Grades C, C+"
function bTargetLabel($pdo, array $target) {
    if ($target['type'] === 'staff') {
        return 'Staff only';
    }
    if ($target['type'] !== 'grades') {
        return 'Everyone';
    }
    $all = bAllGrades($pdo);
    $names = [];
    foreach ($target['grades'] as $id) {
        $names[] = $all[$id]['short'] ?? ('#' . $id);
    }
    if (!$names) {
        return 'No grades';
    }
    return (count($names) === 1 ? 'Grade ' : 'Grades ') . implode(', ', $names);
}

function bAttachmentName($json) {
    if (empty($json)) {
        return null;
    }
    $list = json_decode((string) $json, true);
    if (!is_array($list) || !$list) {
        return null;
    }
    return (string) ($list[0]['name'] ?? 'attachment');
}

function bLoad($pdo, $id, $forUpdate = false) {
    $stmt = $pdo->prepare("SELECT * FROM EmailBroadcasts WHERE BroadcastId = ?" . ($forUpdate ? " FOR UPDATE" : ""));
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// The signed-in person (for preview and test)
function bMe($pdo) {
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.Email, u.FirstName, u.LastName, u.PlayerId, u.UnsubscribeToken, c.ShortName AS GradeShort
        FROM Users u
        LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
        WHERE u.UserId = ?
    ");
    $stmt->execute([(int) $_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// Request body: a form (may carry an attachment) or JSON
function bReadBody() {
    $isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
    if ($isMultipart) {
        return [$_POST, true];
    }
    $data = json_decode(file_get_contents('php://input'), true);
    return [is_array($data) ? $data : null, false];
}

// Validate the target. Returns ['type' => ..., 'grades' => [ids]]
function bReadTarget($pdo, array $data) {
    $type = is_string($data['targetType'] ?? null) ? $data['targetType'] : '';
    if (!in_array($type, ['all', 'grades', 'staff'], true)) {
        bFail('Choose who to send it to.', 400, 'target');
    }
    $grades = [];
    if ($type === 'grades') {
        $all = bAllGrades($pdo);
        foreach (kbfBroadcastGradeIds($data['targetGrades'] ?? '') as $id) {
            if (!isset($all[$id]) || !$all[$id]['active']) {
                bFail('One of the chosen grades is no longer available. Please reload the page.', 400, 'target');
            }
            $grades[] = $id;
        }
        if (!$grades) {
            bFail('Pick at least one grade.', 400, 'target');
        }
        if (strlen(implode(',', $grades)) > 255) {
            bFail('Too many grades chosen.', 400, 'target');
        }
    }
    return ['type' => $type, 'grades' => $grades];
}

// Validate subject and message. Returns [subject (escaped as stored), bodyHtml (cleaned)]
function bReadMessage(array $data) {
    $rawSubject = is_string($data['subject'] ?? null) ? $data['subject'] : '';
    $subject = sanitizeInput($rawSubject);
    if ($subject === '' || strlen($subject) > 255) {
        bFail('The subject is required and must be 1 to 255 characters.', 400, 'subject');
    }
    $bodyHtml = kbfCleanEmailHtml(is_string($data['bodyHtml'] ?? null) ? $data['bodyHtml'] : '');
    if (trim(strip_tags($bodyHtml)) === '') {
        bFail('The message is required.', 400, 'body');
    }
    $unknown = kbfUnknownPlaceholders($rawSubject . ' ' . $bodyHtml, KBF_BROADCAST_PLACEHOLDERS);
    if ($unknown) {
        bFail('Unknown placeholder: {' . implode('}, {', $unknown) . '}. Use the Insert placeholder list.', 400, 'body');
    }
    return [$subject, $bodyHtml];
}

// A new attachment from the form, stored like the old page did:
//   link   uploads/broadcast-files/ under a random name, linked from the email
//   attach storage/email-attachments/, attached to every email
// Returns null (no file) or ['mode', 'path', 'name', 'stored']
function bStoreAttachment($pdo, $isMultipart, array $data) {
    $file = $_FILES['attachment'] ?? null;
    if (!$isMultipart || !$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $mb = kbfAttachmentMaxBytes() / 1048576;
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        bFail('The attachment could not be received. It must be ' . $mb . ' MB or smaller.', 400, 'attachment');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > kbfAttachmentMaxBytes()) {
        bFail('The attachment must be ' . $mb . ' MB or smaller.', 400, 'attachment');
    }
    $check = kbfCheckAttachmentFile($file['tmp_name'], $file['name']);
    if (isset($check['error'])) {
        bFail($check['error'], 400, 'attachment');
    }
    $mode = ($data['attachmentMode'] ?? 'link') === 'attach' ? 'attach' : 'link';
    $storedName = bin2hex(random_bytes(16)) . '.' . $check['ext'];
    if ($mode === 'link') {
        $folder = dirname(__DIR__) . '/uploads/broadcast-files';
        if (!is_dir($folder)) {
            @mkdir($folder, 0755, true);
        }
    } else {
        $folder = kbfAttachmentFolder();
    }
    $target = $folder . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        bFail('The attachment could not be saved.', 500, 'attachment');
    }
    return ['mode' => $mode, 'path' => $target, 'name' => kbfCleanFileName($file['name']), 'stored' => $storedName];
}

function bDropAttachment($attachment) {
    if ($attachment !== null && is_file($attachment['path'])) {
        @unlink($attachment['path']);
    }
}

// The download link added to the message for a "link" attachment
function bLinkHtml($pdo, array $attachment) {
    $url = kbfSiteLink($pdo) . '/uploads/broadcast-files/' . $attachment['stored'];
    return '<p><strong>Download:</strong> <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($attachment['name'], ENT_NOQUOTES, 'UTF-8') . '</a></p>';
}

function bAttachJson(array $attachment) {
    return json_encode([['file' => KBF_ATTACHMENT_DIR . '/' . $attachment['stored'], 'name' => $attachment['name']]],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// Seconds from now until a UK date and time, checked against 10 minutes to 60 days
function bScheduleDelta($value) {
    $ts = kbfBroadcastParseLocal($value);
    if ($ts === null) {
        bFail('Choose a date and time.', 400, 'when');
    }
    $delta = $ts - time();
    if ($delta < KBF_BROADCAST_MIN_AHEAD) {
        bFail('Choose a time at least 10 minutes from now (UK time).', 400, 'when');
    }
    if ($delta > KBF_BROADCAST_MAX_AHEAD) {
        bFail('Choose a time within the next 60 days.', 400, 'when');
    }
    return [$delta, $ts];
}

function bAuditDetails($subject, $pdo, array $target, $extra = '') {
    return 'Subject: ' . bPlain($subject) . '; sent to: ' . bTargetLabel($pdo, $target) . ($extra !== '' ? '; ' . $extra : '');
}

// One row for the "Sent and scheduled" table
function bListRow($pdo, array $r) {
    $status = bStatus($r['Status']);
    $target = kbfBroadcastTargetOf($r);
    $scheduledLabel = null;
    if ($status === 'scheduled' && isset($r['SecondsAhead']) && $r['SecondsAhead'] !== null) {
        $scheduledLabel = kbfBroadcastLabel(time() + (int) $r['SecondsAhead']);
    }
    return [
        'id' => (int) $r['BroadcastId'],
        'subject' => bPlain($r['Subject']),
        'status' => $status,
        'sentTo' => bTargetLabel($pdo, $target),
        'total' => (int) $r['TotalRecipients'],
        'sent' => (int) $r['SentCount'],
        'failed' => (int) $r['FailedCount'],
        'created' => (string) $r['CreatedDate'],
        'completed' => $r['CompletedDate'] ?? null,
        'scheduledLabel' => $scheduledLabel,
        'hasAttachment' => bAttachmentName($r['AttachmentsJson'] ?? null) !== null,
    ];
}

// ============================================
// GET
// ============================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    try {
        if ($action === 'list') {
            $ready = kbfBroadcastSchemaReady($pdo);
            $stmt = $pdo->query("
                SELECT b.*" . ($ready ? ", TIMESTAMPDIFF(SECOND, NOW(), b.ScheduledFor) AS SecondsAhead" : "") . "
                FROM EmailBroadcasts b
                ORDER BY b.BroadcastId DESC
                LIMIT " . KBF_BROADCAST_LIST_LIMIT
            );
            $rows = array_map(function ($r) use ($pdo) { return bListRow($pdo, $r); }, $stmt->fetchAll());
            $anySending = false;
            foreach ($rows as $r) {
                if ($r['status'] === 'sending') {
                    $anySending = true;
                }
            }
            $grades = [];
            foreach (kbfActiveGrades($pdo) as $g) {
                $grades[] = ['id' => (int) $g['CategoryId'], 'shortName' => bPlain($g['ShortName']), 'name' => bPlain($g['Name'])];
            }
            sendJSON([
                'success' => true,
                'broadcasts' => $rows,
                'anySending' => $anySending,
                'grades' => $grades,
                'perDay' => kbfBroadcastPerDay($pdo),
                'placeholders' => array_map(function ($key) {
                    return ['key' => $key, 'description' => KBF_PLACEHOLDERS[$key][0] ?? $key];
                }, KBF_BROADCAST_PLACEHOLDERS),
                'schemaReady' => $ready,
                'canEdit' => !isImpersonating(),
            ]);
        }

        if ($action === 'count') {
            $type = $_GET['target'] ?? 'all';
            if (!in_array($type, ['all', 'grades', 'staff'], true)) {
                bFail('Invalid target');
            }
            $grades = $type === 'grades' ? kbfBroadcastGradeIds($_GET['grades'] ?? '') : [];
            $counts = kbfBroadcastCounts($pdo, $type, $grades);
            $perDay = kbfBroadcastPerDay($pdo);
            sendJSON([
                'success' => true,
                'recipients' => $counts['recipients'],
                'unsubscribed' => $counts['unsubscribed'],
                'perDay' => $perDay,
                'days' => kbfBroadcastDays($counts['recipients'], $perDay),
            ]);
        }

        if ($action === 'get') {
            bNeedSchema($pdo);
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            $row = $id ? bLoad($pdo, $id) : null;
            if (!$row) {
                bFail('Broadcast not found', 404);
            }
            $status = bStatus($row['Status']);
            if (!in_array($status, ['draft', 'scheduled'], true)) {
                bFail('Only drafts and scheduled broadcasts can be edited.');
            }
            $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), ScheduledFor) FROM EmailBroadcasts WHERE BroadcastId = ?");
            $stmt->execute([(int) $id]);
            $ahead = $stmt->fetchColumn();
            $target = kbfBroadcastTargetOf($row);
            sendJSON([
                'success' => true,
                'broadcast' => [
                    'id' => (int) $row['BroadcastId'],
                    'status' => $status,
                    'subject' => bPlain($row['Subject']),
                    'bodyHtml' => (string) $row['BodyHtml'],
                    'targetType' => $target['type'],
                    'targetGrades' => $target['grades'],
                    'scheduledFor' => ($status === 'scheduled' && $ahead !== null && $ahead !== false) ? kbfBroadcastLocalValue(time() + (int) $ahead) : null,
                    'attachmentName' => bAttachmentName($row['AttachmentsJson'] ?? null),
                ],
            ]);
        }

        bFail('Unknown action');
    } catch (PDOException $e) {
        error_log('broadcasts.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST
// ============================================
if ($method !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

list($data, $isMultipart) = bReadBody();
if (!is_array($data)) {
    bFail('Invalid request body');
}
$action = is_string($data['action'] ?? null) ? $data['action'] : '';
if ($action !== 'preview') {
    // Every change (and sending a test) is blocked while viewing as someone else
    requireNotImpersonating();
}
$userId = (int) $_SESSION['user_id'];

try {
    // ---------- PREVIEW ----------
    if ($action === 'preview') {
        list($subject, $bodyHtml) = bReadMessage($data);
        $me = bMe($pdo) ?: [];
        $rendered = kbfBroadcastRender($pdo, $subject, $bodyHtml, $me);
        $footer = "<p>You're receiving this as a registered KBF-UK member. To stop receiving these emails, <a href='#'>unsubscribe here</a>.</p>"
            . "<p><strong>KBF-UK</strong> | kbf-uk.org</p>";
        sendJSON([
            'success' => true,
            'subject' => $rendered['subject'],
            'html' => kbfEmailLayout(kbfCleanEmailHtml($rendered['html']), $footer),
        ]);
    }

    // ---------- SEND A TEST TO ME ----------
    if ($action === 'test') {
        list($subject, $bodyHtml) = bReadMessage($data);
        $me = bMe($pdo);
        if (!$me || !filter_var((string) $me['Email'], FILTER_VALIDATE_EMAIL)) {
            bFail('Your account has no email address to send the test to.');
        }
        $attachments = [];
        // A file attached to every email on the saved broadcast
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id && empty($data['removeAttachment'])) {
            $row = bLoad($pdo, $id);
            if ($row && !empty($row['AttachmentsJson'])) {
                $list = json_decode((string) $row['AttachmentsJson'], true);
                if (is_array($list)) {
                    $attachments = $list;
                }
            }
        }
        // A newly chosen file is not stored for a test: it is attached as it is
        $file = $_FILES['attachment'] ?? null;
        if ($isMultipart && $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && (int) $file['size'] > 0 && (int) $file['size'] <= kbfAttachmentMaxBytes()) {
            $check = kbfCheckAttachmentFile($file['tmp_name'], $file['name']);
            if (isset($check['error'])) {
                bFail($check['error'], 400, 'attachment');
            }
            $name = kbfCleanFileName($file['name']);
            if (($data['attachmentMode'] ?? 'link') === 'attach') {
                $attachments = [['path' => $file['tmp_name'], 'name' => $name]];
            } else {
                $bodyHtml .= '<p><strong>Download:</strong> ' . htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8')
                    . ' (the link is added when the broadcast is saved)</p>';
            }
        }

        $token = (string) ($me['UnsubscribeToken'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("UPDATE Users SET UnsubscribeToken = ? WHERE UserId = ?");
            $stmt->execute([$token, (int) $me['UserId']]);
        }
        $rendered = kbfBroadcastRender($pdo, $subject, $bodyHtml, $me);
        $status = sendBroadcastEmail(
            $me['Email'],
            bPlain($me['FirstName']),
            '[Test] ' . $rendered['subject'],
            $rendered['html'],
            kbfSiteLink($pdo) . '/unsubscribe.php?token=' . urlencode($token),
            null,
            null,
            $attachments,
            'normal'
        );
        auditLog('broadcast_test_sent', 'broadcast', $id ?: null, 'Subject: ' . bPlain($subject) . '; to: ' . $me['Email'] . '; result: ' . $status);
        if ($status === 'failed') {
            bFail('The test could not be sent: ' . kbfMailLastError(), 502);
        }
        sendJSON([
            'success' => true,
            'message' => $status === 'sent'
                ? 'Test sent to ' . $me['Email'] . '.'
                : 'The test is waiting and will go out when sending is allowed again.',
        ]);
    }

    // ---------- SAVE DRAFT / SCHEDULE / SEND NOW ----------
    if (in_array($action, ['saveDraft', 'schedule', 'sendNow'], true)) {
        bNeedSchema($pdo);
        list($subject, $bodyHtml) = bReadMessage($data);
        $target = bReadTarget($pdo, $data);
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $delta = null;
        $whenTs = null;
        if ($action === 'schedule') {
            list($delta, $whenTs) = bScheduleDelta($data['scheduledFor'] ?? '');
        }

        $attachment = bStoreAttachment($pdo, $isMultipart, $data);
        if ($attachment !== null && $attachment['mode'] === 'link') {
            $bodyHtml .= bLinkHtml($pdo, $attachment);
        }

        $status = $action === 'saveDraft' ? 'draft' : ($action === 'schedule' ? 'scheduled' : 'sending');
        $gradesCsv = $target['type'] === 'grades' ? implode(',', $target['grades']) : null;
        $singleGrade = ($target['type'] === 'grades' && count($target['grades']) === 1) ? $target['grades'][0] : null;

        try {
            $pdo->beginTransaction();

            $existing = null;
            if ($id !== null) {
                $existing = bLoad($pdo, $id, true);
                if (!$existing) {
                    $pdo->rollBack();
                    bDropAttachment($attachment);
                    bFail('Broadcast not found', 404);
                }
                if (!in_array(bStatus($existing['Status']), ['draft', 'scheduled'], true)) {
                    $pdo->rollBack();
                    bDropAttachment($attachment);
                    bFail('This broadcast has already started and can no longer be changed.', 409);
                }
            }

            // Attached-to-every-email file: a new one replaces the old, or it is removed
            $attachJson = $existing['AttachmentsJson'] ?? null;
            if ($attachment !== null && $attachment['mode'] === 'attach') {
                $attachJson = bAttachJson($attachment);
            } elseif (!empty($data['removeAttachment'])) {
                $attachJson = null;
            }

            $scheduledSql = $delta !== null ? 'DATE_ADD(NOW(), INTERVAL ' . (int) $delta . ' SECOND)' : 'NULL';
            if ($existing) {
                $stmt = $pdo->prepare("
                    UPDATE EmailBroadcasts
                    SET Subject = ?, BodyHtml = ?, TargetType = ?, TargetCategoryId = ?, TargetGrades = ?,
                        Status = ?, ScheduledFor = $scheduledSql, AttachmentsJson = ?, UpdatedDate = NOW()
                    WHERE BroadcastId = ?
                ");
                $stmt->execute([$subject, $bodyHtml, $target['type'], $singleGrade, $gradesCsv, $status, $attachJson, $id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO EmailBroadcasts
                        (Subject, BodyHtml, TargetType, TargetCategoryId, TargetGrades, Status, TotalRecipients,
                         SentCount, FailedCount, CreatedByAdminId, CreatedDate, UpdatedDate, ScheduledFor, AttachmentsJson)
                    VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, NOW(), NOW(), $scheduledSql, ?)
                ");
                $stmt->execute([$subject, $bodyHtml, $target['type'], $singleGrade, $gradesCsv, $status, $userId, $attachJson]);
                $id = (int) $pdo->lastInsertId();
            }

            $queued = null;
            if ($action === 'sendNow') {
                $queued = kbfBroadcastFillQueue($pdo, $id, $target['type'], $target['grades']);
                if ($queued === 0) {
                    $pdo->rollBack();
                    bDropAttachment($attachment);
                    bFail('Nobody matches this target, so there is nothing to send.', 400, 'target');
                }
            } else {
                $counts = kbfBroadcastCounts($pdo, $target['type'], $target['grades']);
                $stmt = $pdo->prepare("UPDATE EmailBroadcasts SET TotalRecipients = ? WHERE BroadcastId = ?");
                $stmt->execute([$counts['recipients'], $id]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            bDropAttachment($attachment);
            throw $e;
        }

        $extra = $attachment !== null ? 'attachment: ' . $attachment['name'] . ' (' . ($attachment['mode'] === 'link' ? 'download link' : 'attached') . ')' : '';
        if ($action === 'saveDraft') {
            auditLog('broadcast_draft_saved', 'broadcast', $id, bAuditDetails($subject, $pdo, $target, $extra));
            sendJSON(['success' => true, 'message' => 'Draft saved.', 'id' => $id]);
        }
        if ($action === 'schedule') {
            $label = kbfBroadcastLabel($whenTs);
            auditLog('broadcast_scheduled', 'broadcast', $id, bAuditDetails($subject, $pdo, $target, 'for ' . $label . ($extra !== '' ? '; ' . $extra : '')));
            sendJSON(['success' => true, 'message' => 'Scheduled for ' . $label . ' (UK time).', 'id' => $id]);
        }
        auditLog('broadcast_sent', 'broadcast', $id, bAuditDetails($subject, $pdo, $target, 'recipients: ' . $queued . ($extra !== '' ? '; ' . $extra : '')));
        sendJSON([
            'success' => true,
            'message' => 'Sending to ' . number_format($queued) . ' member' . ($queued === 1 ? '' : 's') . '. It goes out in the background within the daily limit.',
            'id' => $id,
            'totalRecipients' => $queued,
        ]);
    }

    // ---------- PAUSE / RESUME / CANCEL ----------
    if (in_array($action, ['pause', 'resume', 'cancel'], true)) {
        bNeedSchema($pdo);
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            bFail('Invalid broadcast');
        }
        $from = [
            'pause' => ['queued', 'sending'],
            'resume' => ['paused'],
            'cancel' => ['queued', 'sending', 'scheduled', 'paused'],
        ][$action];
        $to = ['pause' => 'paused', 'resume' => 'sending', 'cancel' => 'cancelled'][$action];

        $pdo->beginTransaction();
        $row = bLoad($pdo, $id, true);
        if (!$row) {
            $pdo->rollBack();
            bFail('Broadcast not found', 404);
        }
        if (!in_array(strtolower((string) $row['Status']), $from, true)) {
            $pdo->rollBack();
            $now = bStatus($row['Status']);
            bFail('This broadcast is ' . $now . ' and cannot be ' . ['pause' => 'paused', 'resume' => 'resumed', 'cancel' => 'cancelled'][$action] . '.', 409);
        }
        $stmt = $pdo->prepare("
            UPDATE EmailBroadcasts
            SET Status = ?" . ($action === 'cancel' ? ", CancelledDate = NOW()" : "") . ", UpdatedDate = NOW()
            WHERE BroadcastId = ?
        ");
        $stmt->execute([$to, $id]);
        $pdo->commit();

        $target = kbfBroadcastTargetOf($row);
        $done = (int) $row['SentCount'];
        $auditKey = ['pause' => 'broadcast_paused', 'resume' => 'broadcast_resumed', 'cancel' => 'broadcast_cancelled'][$action];
        auditLog($auditKey, 'broadcast', $id, bAuditDetails($row['Subject'], $pdo, $target, $done . ' of ' . (int) $row['TotalRecipients'] . ' sent'));
        $messages = [
            'pause' => 'Paused. Nothing more is sent until you resume it.',
            'resume' => 'Resumed. Sending carries on within the daily limit.',
            'cancel' => 'Cancelled. Nothing more is sent.',
        ];
        sendJSON(['success' => true, 'message' => $messages[$action]]);
    }

    // ---------- DUPLICATE ----------
    if ($action === 'duplicate') {
        bNeedSchema($pdo);
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $row = $id ? bLoad($pdo, $id) : null;
        if (!$row) {
            bFail('Broadcast not found', 404);
        }
        $target = kbfBroadcastTargetOf($row);
        // Keep only grades that are still active
        $all = bAllGrades($pdo);
        $grades = array_values(array_filter($target['grades'], function ($g) use ($all) { return isset($all[$g]) && $all[$g]['active']; }));
        if ($target['type'] === 'grades' && !$grades) {
            $target = ['type' => 'all', 'grades' => []];
        } else {
            $target['grades'] = $grades;
        }
        $gradesCsv = $target['type'] === 'grades' ? implode(',', $target['grades']) : null;
        $singleGrade = ($target['type'] === 'grades' && count($target['grades']) === 1) ? $target['grades'][0] : null;
        $counts = kbfBroadcastCounts($pdo, $target['type'], $target['grades']);

        $stmt = $pdo->prepare("
            INSERT INTO EmailBroadcasts
                (Subject, BodyHtml, TargetType, TargetCategoryId, TargetGrades, Status, TotalRecipients,
                 SentCount, FailedCount, CreatedByAdminId, CreatedDate, UpdatedDate, ScheduledFor, AttachmentsJson)
            VALUES (?, ?, ?, ?, ?, 'draft', ?, 0, 0, ?, NOW(), NOW(), NULL, ?)
        ");
        $stmt->execute([$row['Subject'], $row['BodyHtml'], $target['type'], $singleGrade, $gradesCsv,
            $counts['recipients'], $userId, $row['AttachmentsJson'] ?? null]);
        $newId = (int) $pdo->lastInsertId();
        auditLog('broadcast_duplicated', 'broadcast', $newId, 'Copy of broadcast #' . (int) $id . '; ' . bAuditDetails($row['Subject'], $pdo, $target));
        sendJSON(['success' => true, 'message' => 'A copy was saved as a draft.', 'id' => $newId]);
    }

    bFail('Unknown action');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('broadcasts.php POST: ' . $e->getMessage());
    $message = (strpos($e->getMessage(), 'AttachmentsJson') !== false)
        ? 'Attaching files needs database/notifications.sql to be run first.'
        : 'Database error occurred';
    sendJSON(['success' => false, 'message' => $message], 500);
}
?>
