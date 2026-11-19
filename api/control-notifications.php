<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/notify.php';

// ============================================
// CONTROL CENTRE: NOTIFICATIONS (control.notifications)
//
//   GET  ?action=list                     every event with its switches
//   GET  ?action=event&key=               one event: emails, attachments,
//                                         placeholders, admin recipients
//   POST {action:'saveSwitches', switches: {key: {person, organiser, admins, news}}}
//   POST {action:'saveEvent', key, templates: {audience: {subject, bodyHtml}},
//         recipients: [userId], delivery: 'immediate'|'daily',
//         reminderDays: 1-60 (results_overdue only: results_reminder_days)}
//   POST {action:'restoreTemplate', key, audience}
//   POST {action:'preview', key, audience, subject, bodyHtml}
//   POST {action:'sendTest', key, audience, subject, bodyHtml}
//   POST multipart action=uploadAttachment, key, audience, file
//   POST {action:'removeAttachment', id}
//
// Order of checks: logged in (401), CSRF on writes (403),
// control.notifications (403). Changes are blocked while impersonating;
// reading and preview are allowed. Every change is written to AuditLog.
// ============================================

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
requirePermission('control.notifications');

const KBF_CHANNEL_COLUMNS = [
    'person' => ['EmailPersonApplies', 'EmailPersonEnabled', 'Email person'],
    'organiser' => ['EmailOrganiserApplies', 'EmailOrganiserEnabled', 'Email organiser'],
    'admins' => ['EmailAdminsApplies', 'EmailAdminsEnabled', 'Email admins'],
    'news' => ['NewsPostApplies', 'NewsPostEnabled', 'News post'],
];

// The wording (and attachments) of the two password emails can only be
// changed by a Super Admin: they carry the password reset link
const KBF_SUPER_ADMIN_TEMPLATE_EVENTS = ['password_reset_requested', 'password_reset_completed'];

function requireTemplateEditor($eventKey) {
    if (in_array($eventKey, KBF_SUPER_ADMIN_TEMPLATE_EVENTS, true) && !isSuperAdmin()) {
        sendJSON(['success' => false, 'message' => 'Only a Super Admin can change the wording of the password emails.'], 403);
    }
}

// ---------- helpers ----------
function readEventKey($value) {
    return (is_string($value) && isset(KBF_NOTIFY_EVENTS[$value])) ? $value : null;
}

// An audience whose email exists for the event
function readAudience($eventKey, $value) {
    return (is_string($value) && isset(KBF_NOTIFY_EVENTS[$eventKey]['audiences'][$value])) ? $value : null;
}

function cleanSubject($value) {
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    return function_exists('mb_substr') ? mb_substr($text, 0, 255, 'UTF-8') : substr($text, 0, 255);
}

function placeholderList($eventKey) {
    return array_map(function ($name) {
        return ['key' => $name, 'description' => KBF_PLACEHOLDERS[$name][0] ?? ''];
    }, kbfEventPlaceholders($eventKey));
}

function eventPermissionLabel($pdo, $permission) {
    if (!$permission) {
        return '';
    }
    try {
        $stmt = $pdo->prepare("SELECT Label FROM Permissions WHERE PermissionKey = ?");
        $stmt->execute([$permission]);
        $label = $stmt->fetchColumn();
        return $label !== false ? (string) $label : $permission;
    } catch (PDOException $e) {
        return $permission;
    }
}

function attachmentTotal($pdo, $templateKey) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(SizeBytes), 0) FROM TemplateAttachments WHERE TemplateKey = ?");
    $stmt->execute([$templateKey]);
    return (int) $stmt->fetchColumn();
}

function publicAttachments($pdo, $templateKey) {
    return array_map(function ($a) {
        return ['id' => $a['id'], 'name' => $a['name'], 'size' => $a['size'], 'mime' => $a['mime']];
    }, kbfTemplateAttachments($pdo, $templateKey));
}

// Values for preview and test emails
function previewEmail($pdo, $eventKey, $subject, $bodyHtml) {
    $clean = kbfCleanEmailHtml($bodyHtml);
    $email = kbfBuildEmail($subject, $clean, kbfSampleValues($pdo));
    $allowed = kbfEventPlaceholders($eventKey);
    $unknown = array_values(array_unique(array_merge(
        kbfUnknownPlaceholders($subject, $allowed),
        kbfUnknownPlaceholders($clean, $allowed)
    )));
    return [$email, $unknown];
}

function missingTableMessage() {
    return 'The notification tables are missing. Please run database/notifications.sql first.';
}

// ============================================
// GET
// ============================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    try {
        if ($action === 'list') {
            $events = [];
            $tableReady = true;
            try {
                $pdo->query("SELECT 1 FROM NotificationEvents LIMIT 1");
            } catch (PDOException $e) {
                $tableReady = false;
            }
            foreach (KBF_NOTIFY_EVENTS as $key => $def) {
                $s = kbfNotifyEventSettings($pdo, $key);
                $channels = [];
                foreach (['person', 'organiser', 'admins', 'news'] as $c) {
                    $channels[$c] = ['applies' => $s[$c]['applies'], 'enabled' => $s[$c]['enabled'] || ($s['locked'] && $s[$c]['applies'])];
                }
                $events[] = [
                    'key' => $key,
                    'group' => $def['group'],
                    'label' => $s['label'],
                    'description' => $def['description'],
                    'sort' => $def['sort'],
                    'locked' => $s['locked'],
                    'channels' => $channels,
                ];
            }
            usort($events, function ($a, $b) { return $a['sort'] <=> $b['sort']; });
            sendJSON([
                'success' => true,
                'groups' => KBF_NOTIFY_GROUPS,
                'events' => $events,
                'tableReady' => $tableReady,
                'canEdit' => !isImpersonating(),
            ]);
        }

        if ($action === 'event') {
            $key = readEventKey($_GET['key'] ?? null);
            if ($key === null) {
                sendJSON(['success' => false, 'message' => 'Unknown notification'], 404);
            }
            $def = KBF_NOTIFY_EVENTS[$key];
            $settings = kbfNotifyEventSettings($pdo, $key);

            $emails = [];
            foreach ($def['audiences'] as $audience => $tabLabel) {
                if (!$settings[$audience]['applies']) {
                    continue;
                }
                $templateKey = kbfTemplateKey($key, $audience);
                $template = kbfGetTemplate($pdo, $templateKey);
                $emails[] = [
                    'audience' => $audience,
                    'tabLabel' => $tabLabel,
                    'enabled' => $settings[$audience]['enabled'] || $settings['locked'],
                    'subject' => $template['subject'],
                    'bodyHtml' => $template['bodyHtml'],
                    'custom' => $template['custom'],
                    'updatedDate' => $template['updatedDate'],
                    'attachments' => publicAttachments($pdo, $templateKey),
                ];
            }

            $admin = null;
            if ($settings['admins']['applies']) {
                $candidates = array_map(function ($r) {
                    return [
                        'userId' => (int) $r['UserId'],
                        'name' => trim(html_entity_decode($r['FirstName'] . ' ' . $r['LastName'], ENT_QUOTES, 'UTF-8')),
                        'email' => $r['Email'],
                        'isSuperAdmin' => (int) $r['IsSuperAdmin'] === 1,
                    ];
                }, kbfNotifyAdminCandidates($pdo, (string) $settings['adminPermission']));
                $chosen = [];
                try {
                    $stmt = $pdo->prepare("SELECT UserId FROM NotificationRecipients WHERE EventKey = ?");
                    $stmt->execute([$key]);
                    $chosen = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                } catch (PDOException $e) {
                    $chosen = [];
                }
                $admin = [
                    'permission' => $settings['adminPermission'],
                    'permissionLabel' => eventPermissionLabel($pdo, $settings['adminPermission']),
                    'candidates' => $candidates,
                    'chosen' => $chosen,
                    'delivery' => $settings['adminDelivery'],
                ];
            }

            sendJSON([
                'success' => true,
                'event' => [
                    'key' => $key,
                    'label' => $settings['label'],
                    'description' => $def['description'],
                    'locked' => $settings['locked'],
                    'news' => $settings['news'],
                ],
                'emails' => $emails,
                'placeholders' => placeholderList($key),
                'admin' => $admin,
                // results_overdue: days after the tournament for the one automatic reminder
                'reminderDays' => $key === 'results_overdue' ? kbfSettingInt('results_reminder_days', 7, 1, 60) : null,
                'dailyLimit' => kbfMailSettings($pdo)['limit'],
                'attachmentLimit' => kbfAttachmentMaxBytes(),
                // The password emails: Super Admins only
                'canEdit' => !isImpersonating()
                    && (!in_array($key, KBF_SUPER_ADMIN_TEMPLATE_EVENTS, true) || isSuperAdmin()),
            ]);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        error_log('control-notifications.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST
// ============================================
if ($method === 'POST') {
    $isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
    if ($isMultipart) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            sendJSON(['success' => false, 'message' => 'Invalid request body'], 400);
        }
    }
    $action = is_string($data['action'] ?? null) ? $data['action'] : '';

    // Preview changes nothing, so it is allowed while impersonating
    if ($action !== 'preview') {
        requireNotImpersonating();
    }

    try {
        // ---------- SWITCHES ----------
        if ($action === 'saveSwitches') {
            $switches = $data['switches'] ?? null;
            if (!is_array($switches)) {
                sendJSON(['success' => false, 'message' => 'Invalid switches'], 400);
            }

            $changes = [];
            $pdo->beginTransaction();
            foreach ($switches as $key => $channels) {
                if (readEventKey($key) === null || !is_array($channels)) {
                    continue;
                }
                $current = kbfNotifyEventSettings($pdo, $key);
                if ($current['locked']) {
                    continue; // locked events cannot be switched off
                }
                foreach (KBF_CHANNEL_COLUMNS as $channel => $cols) {
                    if (!array_key_exists($channel, $channels) || !$current[$channel]['applies']) {
                        continue;
                    }
                    $on = $channels[$channel] === true;
                    if ($on === $current[$channel]['enabled']) {
                        continue;
                    }
                    $stmt = $pdo->prepare("UPDATE NotificationEvents SET {$cols[1]} = ? WHERE EventKey = ?");
                    $stmt->execute([$on ? 1 : 0, $key]);
                    if ($stmt->rowCount() === 0) {
                        $pdo->rollBack();
                        sendJSON(['success' => false, 'message' => missingTableMessage()], 409);
                    }
                    $changes[] = [$key, $cols[2], $current[$channel]['enabled'], $on];
                }
            }
            $pdo->commit();

            foreach ($changes as $c) {
                auditLog('notification_switch_changed', 'notification', null,
                    $c[0] . ': ' . $c[1] . ' ' . ($c[2] ? 'on' : 'off') . ' -> ' . ($c[3] ? 'on' : 'off'));
            }
            sendJSON([
                'success' => true,
                'message' => $changes ? 'Changes saved' : 'Nothing to change',
                'changed' => count($changes),
            ]);
        }

        // ---------- SAVE AN EVENT'S EMAILS AND RECIPIENTS ----------
        if ($action === 'saveEvent') {
            $key = readEventKey($data['key'] ?? null);
            if ($key === null) {
                sendJSON(['success' => false, 'message' => 'Unknown notification'], 404);
            }
            $def = KBF_NOTIFY_EVENTS[$key];
            $settings = kbfNotifyEventSettings($pdo, $key);
            $allowed = kbfEventPlaceholders($key);

            // Validate every email first
            $templates = is_array($data['templates'] ?? null) ? $data['templates'] : [];
            $toSave = [];
            $warnings = [];
            foreach ($templates as $audience => $t) {
                if (readAudience($key, $audience) === null || !$settings[$audience]['applies'] || !is_array($t)) {
                    continue;
                }
                if (!is_string($t['subject'] ?? '') || !is_string($t['bodyHtml'] ?? '')) {
                    sendJSON(['success' => false, 'message' => 'Invalid request body'], 400);
                }
                $rawBody = (string) ($t['bodyHtml'] ?? '');
                $subject = cleanSubject($t['subject'] ?? '');
                $body = kbfCleanEmailHtml($rawBody);
                $tab = $def['audiences'][$audience];
                // Links: a link placeholder only as a whole address or plain text
                foreach ([$subject, $rawBody, $body] as $checkText) {
                    $linkError = kbfCheckLinkPlaceholders($checkText);
                    if ($linkError !== null) {
                        sendJSON(['success' => false, 'message' => $tab . ': ' . $linkError, 'audience' => $audience], 400);
                    }
                }
                // The password emails: Super Admins only (only when the wording changes)
                $current = kbfGetTemplate($pdo, kbfTemplateKey($key, $audience));
                if ($current['subject'] !== $subject
                    || ($current['bodyHtml'] !== $body && kbfCleanEmailHtml($current['bodyHtml']) !== $body)) {
                    requireTemplateEditor($key);
                }
                if ($subject === '') {
                    sendJSON(['success' => false, 'message' => $tab . ': the subject is required.', 'audience' => $audience], 400);
                }
                if (trim(strip_tags($body)) === '') {
                    sendJSON(['success' => false, 'message' => $tab . ': the message is empty.', 'audience' => $audience], 400);
                }
                $unknown = array_values(array_unique(array_merge(
                    kbfUnknownPlaceholders($subject, $allowed),
                    kbfUnknownPlaceholders($body, $allowed)
                )));
                foreach ($unknown as $name) {
                    $warnings[] = $tab . ': {' . $name . '} is not a placeholder for this email and will be sent as written.';
                }
                $toSave[$audience] = [$subject, $body];
            }

            // Recipients and delivery (events with admin emails only)
            $newRecipients = null;
            $newDelivery = null;
            if ($settings['admins']['applies']) {
                if (isset($data['recipients']) && is_array($data['recipients'])) {
                    $candidateIds = array_map(function ($r) { return (int) $r['UserId']; },
                        kbfNotifyAdminCandidates($pdo, (string) $settings['adminPermission']));
                    $newRecipients = [];
                    foreach ($data['recipients'] as $id) {
                        $id = filter_var($id, FILTER_VALIDATE_INT);
                        if (!$id || !in_array($id, $candidateIds, true)) {
                            sendJSON(['success' => false, 'message' => 'One of the chosen people cannot receive this email'], 400);
                        }
                        $newRecipients[$id] = true;
                    }
                    $newRecipients = array_keys($newRecipients);
                    sort($newRecipients);
                }
                if (isset($data['delivery'])) {
                    if (!in_array($data['delivery'], ['immediate', 'daily'], true)) {
                        sendJSON(['success' => false, 'message' => 'Invalid delivery choice'], 400);
                    }
                    $newDelivery = $data['delivery'];
                }
            }

            // results_overdue: "Send automatically this many days after the tournament"
            $newReminderDays = null;
            if ($key === 'results_overdue' && array_key_exists('reminderDays', $data)) {
                $days = filter_var($data['reminderDays'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
                if ($days === false) {
                    sendJSON(['success' => false, 'message' => 'Days after the tournament must be a whole number from 1 to 60.'], 400);
                }
                $newReminderDays = (int) $days;
            }

            $audit = [];
            $pdo->beginTransaction();

            if ($newReminderDays !== null) {
                $oldReminderDays = kbfSettingInt('results_reminder_days', 7, 1, 60);
                if ($newReminderDays !== $oldReminderDays) {
                    $stmt = $pdo->prepare("
                        INSERT INTO Settings (SettingKey, SettingValue) VALUES ('results_reminder_days', ?)
                        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
                    ");
                    $stmt->execute([(string) $newReminderDays]);
                    $audit[] = ['setting_changed', 'results_reminder_days: ' . $oldReminderDays . ' -> ' . $newReminderDays];
                }
            }

            foreach ($toSave as $audience => list($subject, $body)) {
                $templateKey = kbfTemplateKey($key, $audience);
                $before = kbfGetTemplate($pdo, $templateKey);
                $default = KBF_EMAIL_DEFAULTS[$templateKey] ?? null;
                $same = $before['subject'] === $subject && $before['bodyHtml'] === $body;
                if ($same) {
                    continue;
                }
                if ($default && $default['subject'] === $subject && kbfCleanEmailHtml($default['body']) === $body) {
                    // Back to the original wording: no row needed
                    $stmt = $pdo->prepare("DELETE FROM EmailTemplates WHERE TemplateKey = ?");
                    $stmt->execute([$templateKey]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO EmailTemplates (TemplateKey, Subject, BodyHtml, UpdatedByUserId, UpdatedDate)
                        VALUES (?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE Subject = VALUES(Subject), BodyHtml = VALUES(BodyHtml),
                            UpdatedByUserId = VALUES(UpdatedByUserId), UpdatedDate = NOW()
                    ");
                    $stmt->execute([$templateKey, $subject, $body, (int) $_SESSION['user_id']]);
                }
                $audit[] = ['email_template_changed', $templateKey . ': subject "' . $before['subject'] . '" -> "' . $subject . '"'
                    . ($before['bodyHtml'] !== $body ? '; wording changed' : '')];
            }

            if ($newRecipients !== null) {
                $stmt = $pdo->prepare("SELECT UserId FROM NotificationRecipients WHERE EventKey = ? ORDER BY UserId");
                $stmt->execute([$key]);
                $beforeRecipients = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                if ($beforeRecipients !== $newRecipients) {
                    $stmt = $pdo->prepare("DELETE FROM NotificationRecipients WHERE EventKey = ?");
                    $stmt->execute([$key]);
                    $insert = $pdo->prepare("INSERT INTO NotificationRecipients (EventKey, UserId) VALUES (?, ?)");
                    foreach ($newRecipients as $id) {
                        $insert->execute([$key, $id]);
                    }
                    $audit[] = ['notification_recipients_changed', $key . ': users ['
                        . implode(', ', $beforeRecipients) . '] -> [' . implode(', ', $newRecipients) . ']'
                        . ($newRecipients ? '' : ' (Super Admins)')];
                }
            }

            if ($newDelivery !== null && $newDelivery !== $settings['adminDelivery']) {
                $stmt = $pdo->prepare("UPDATE NotificationEvents SET AdminDelivery = ? WHERE EventKey = ?");
                $stmt->execute([$newDelivery, $key]);
                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    sendJSON(['success' => false, 'message' => missingTableMessage()], 409);
                }
                $audit[] = ['notification_delivery_changed', $key . ': ' . $settings['adminDelivery'] . ' -> ' . $newDelivery];
            }

            $pdo->commit();

            foreach ($audit as $a) {
                auditLog($a[0], 'notification', null, $a[1]);
            }

            sendJSON([
                'success' => true,
                'message' => $audit ? 'Email saved' : 'Nothing to change',
                'warnings' => $warnings,
            ]);
        }

        // ---------- RESTORE THE ORIGINAL WORDING ----------
        if ($action === 'restoreTemplate') {
            $key = readEventKey($data['key'] ?? null);
            $audience = $key ? readAudience($key, $data['audience'] ?? null) : null;
            if ($key === null || $audience === null) {
                sendJSON(['success' => false, 'message' => 'Unknown email'], 404);
            }
            requireTemplateEditor($key);
            $templateKey = kbfTemplateKey($key, $audience);
            $stmt = $pdo->prepare("DELETE FROM EmailTemplates WHERE TemplateKey = ?");
            $stmt->execute([$templateKey]);
            if ($stmt->rowCount() > 0) {
                auditLog('email_template_restored', 'notification', null, $templateKey . ': original wording restored');
            }
            $template = kbfGetTemplate($pdo, $templateKey);
            sendJSON([
                'success' => true,
                'message' => 'The original wording is back',
                'subject' => $template['subject'],
                'bodyHtml' => $template['bodyHtml'],
            ]);
        }

        // ---------- PREVIEW (sample details, nothing saved) ----------
        if ($action === 'preview' || $action === 'sendTest') {
            $key = readEventKey($data['key'] ?? null);
            $audience = $key ? readAudience($key, $data['audience'] ?? null) : null;
            if ($key === null || $audience === null) {
                sendJSON(['success' => false, 'message' => 'Unknown email'], 404);
            }
            $subject = cleanSubject($data['subject'] ?? '');
            if ($subject === '') {
                sendJSON(['success' => false, 'message' => 'The subject is required.'], 400);
            }
            list($email, $unknown) = previewEmail($pdo, $key, $subject, (string) ($data['bodyHtml'] ?? ''));
            $warnings = array_map(function ($n) { return '{' . $n . '} is not a placeholder for this email.'; }, $unknown);

            if ($action === 'preview') {
                sendJSON([
                    'success' => true,
                    'subject' => $email['subject'],
                    'html' => $email['html'],
                    'warnings' => $warnings,
                ]);
            }

            // Send a test to the signed-in person, with this email's attachments
            $stmt = $pdo->prepare("SELECT Email, FirstName, LastName FROM Users WHERE UserId = ?");
            $stmt->execute([(int) $_SESSION['user_id']]);
            $me = $stmt->fetch();
            if (!$me || !filter_var(trim((string) $me['Email']), FILTER_VALIDATE_EMAIL)) {
                sendJSON(['success' => false, 'message' => 'Your account has no email address to send a test to.'], 400);
            }
            $attachments = array_map(function ($a) {
                return ['file' => $a['file'], 'name' => $a['name']];
            }, kbfTemplateAttachments($pdo, kbfTemplateKey($key, $audience)));

            $status = kbfSendEmail([
                'eventKey' => 'test',
                'priority' => 'normal',
                'toEmail' => trim((string) $me['Email']),
                'toName' => trim($me['FirstName'] . ' ' . $me['LastName']),
                'subject' => '[Test] ' . $email['subject'],
                'html' => $email['html'],
                'text' => $email['text'],
                'attachments' => $attachments,
                'relatedType' => 'test',
            ]);
            auditLog('email_test_sent', 'notification', null, kbfTemplateKey($key, $audience) . ': test ' . $status);

            $ok = $status !== 'failed';
            sendJSON([
                'success' => $ok,
                'message' => $status === 'sent' ? 'Test sent to ' . trim((string) $me['Email'])
                    : ($status === 'waiting' ? 'The test will go out when the daily allowance frees up.'
                        : 'The test could not be sent: ' . rtrim(kbfMailLastError(), '.') . '.'),
                'emailStatus' => $status,
                'warnings' => $warnings,
            ], $ok ? 200 : 500);
        }

        // ---------- ATTACHMENTS ----------
        if ($action === 'uploadAttachment') {
            $key = readEventKey($data['key'] ?? null);
            $audience = $key ? readAudience($key, $data['audience'] ?? null) : null;
            if ($key === null || $audience === null) {
                sendJSON(['success' => false, 'message' => 'Unknown email'], 404);
            }
            requireTemplateEditor($key);
            $file = $_FILES['file'] ?? null;
            if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $tooBig = $file && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
                sendJSON(['success' => false, 'message' => $tooBig ? 'The file is too large.' : 'No file was received.'], 400);
            }
            $size = (int) $file['size'];
            $templateKey = kbfTemplateKey($key, $audience);
            if ($size < 1 || attachmentTotal($pdo, $templateKey) + $size > kbfAttachmentMaxBytes()) {
                sendJSON(['success' => false, 'message' => 'Attachments can be up to ' . (kbfAttachmentMaxBytes() / 1048576) . ' MB in total for each email.'], 400);
            }
            $check = kbfCheckAttachmentFile($file['tmp_name'], $file['name']);
            if (isset($check['error'])) {
                sendJSON(['success' => false, 'message' => $check['error']], 400);
            }

            $storedName = bin2hex(random_bytes(16)) . '.' . $check['ext'];
            $target = kbfAttachmentFolder() . '/' . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                sendJSON(['success' => false, 'message' => 'The file could not be saved.'], 500);
            }
            $originalName = kbfCleanFileName($file['name']);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO TemplateAttachments (TemplateKey, OriginalName, StoredName, MimeType, SizeBytes, UploadedDate)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$templateKey, $originalName, $storedName, $check['mime'], $size]);
            } catch (PDOException $e) {
                @unlink($target);
                throw $e;
            }
            auditLog('email_attachment_added', 'notification', null, $templateKey . ': ' . $originalName . ' (' . $size . ' bytes)');

            sendJSON([
                'success' => true,
                'message' => 'File added',
                'attachments' => publicAttachments($pdo, $templateKey),
            ]);
        }

        if ($action === 'removeAttachment') {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                sendJSON(['success' => false, 'message' => 'Invalid file'], 400);
            }
            $stmt = $pdo->prepare("SELECT TemplateKey, OriginalName, StoredName FROM TemplateAttachments WHERE Id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendJSON(['success' => false, 'message' => 'File not found'], 404);
            }
            requireTemplateEditor(explode('.', (string) $row['TemplateKey'])[0]);
            $stmt = $pdo->prepare("DELETE FROM TemplateAttachments WHERE Id = ?");
            $stmt->execute([$id]);

            // Keep the file while a waiting email still needs it
            $inUse = false;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM EmailLog WHERE Status = 'waiting' AND AttachmentsJson LIKE ?");
                $stmt->execute(['%' . $row['StoredName'] . '%']);
                $inUse = (int) $stmt->fetchColumn() > 0;
            } catch (PDOException $e) {
                $inUse = true;
            }
            if (!$inUse && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|docx)$/', $row['StoredName'])) {
                @unlink(kbfAttachmentFolder() . '/' . $row['StoredName']);
            }
            auditLog('email_attachment_removed', 'notification', null, $row['TemplateKey'] . ': ' . $row['OriginalName']);

            sendJSON([
                'success' => true,
                'message' => 'File removed',
                'attachments' => publicAttachments($pdo, $row['TemplateKey']),
            ]);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('control-notifications.php POST: ' . $e->getMessage());
        $message = (strpos($e->getMessage(), "doesn't exist") !== false) ? missingTableMessage() : 'Database error occurred';
        sendJSON(['success' => false, 'message' => $message], 500);
    }
}

sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
?>
