<?php
// ============================================
// KBF-UK: ONE NOTIFY FUNCTION
//
//   notify($eventKey, $context)
//
// Checks the event's switches (NotificationEvents, or the code's starting
// values when that table is missing), builds each email from its template
// (api/email-templates.php), finds the recipients, sends through the shared
// sender (api/mailer.php) and creates the news post when that channel is on.
//
// Recipients:
//   person     the player or staff member the event is about
//   organiser  the tournament's creator
//   admins     the chosen recipients (NotificationRecipients) who are active
//              and still hold the event's permission; Super Admins when
//              nobody (valid) is chosen
// Anyone without an email address is skipped. One person never receives the
// same event twice. Admin emails set to "daily" are held in
// NotificationDigest and sent by the nightly ranking-calculator job.
//
// $context:
//   person     user id, or ['email', 'firstName', 'lastName'] (an applicant)
//   organiser  user id, or the same array shape
//   values     placeholder values, e.g. ['tournament_name' => ...]
//   secrets    placeholder names never stored (e.g. ['reset_link'])
//   news       ['create' => bool|null, 'title', 'content', 'image', 'authorId']
//              create null: follow the event's news switch
//   relatedType, relatedId   stored with the EmailLog row
//   forcePerson, forceOrganiser   true: send that email even when the
//              event's switch is off (an admin chose to send it)
//   skipPerson true: do not send the person's email this time (an admin
//              chose not to); ignored for a locked event
//
// Called inside a database transaction, notify() waits: the caller runs
// kbfNotifyFlush() after commit (nothing is sent for a rolled-back change).
// Errors are logged and never undo or block the action that caused them.
//
// Returns ['person' => status, 'organiser' => status,
//          'admins' => ['sent' => n, 'waiting' => n, 'failed' => n, 'held' => n] or status,
//          'news' => post id or status, 'errors' => [audience => reason]]
// status: 'sent', 'waiting', 'failed', 'off' (switched off), 'none' (nobody
// to send to), 'n/a' (channel does not apply), 'deferred'
// ============================================

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email-templates.php';

// ============================================
// EVENT SETTINGS
// ============================================
function kbfNotifyEventSettings($pdo, $eventKey) {
    $def = KBF_NOTIFY_EVENTS[$eventKey] ?? null;
    if ($def === null) {
        return null;
    }
    $settings = [
        'key' => $eventKey,
        'label' => $def['label'],
        'priority' => $def['priority'],
        'adminPermission' => $def['adminPermission'],
        'adminDelivery' => 'immediate',
        'locked' => (bool) $def['locked'],
        'person' => ['applies' => (bool) $def['person'][0], 'enabled' => (bool) $def['person'][1]],
        'organiser' => ['applies' => (bool) $def['organiser'][0], 'enabled' => (bool) $def['organiser'][1]],
        'admins' => ['applies' => (bool) $def['admins'][0], 'enabled' => (bool) $def['admins'][1]],
        'news' => ['applies' => (bool) $def['news'][0], 'enabled' => (bool) $def['news'][1]],
    ];
    try {
        $stmt = $pdo->prepare("SELECT * FROM NotificationEvents WHERE EventKey = ?");
        $stmt->execute([$eventKey]);
        $row = $stmt->fetch();
        if ($row) {
            $settings['label'] = $row['Label'];
            $settings['priority'] = $row['Priority'] === 'important' ? 'important' : 'normal';
            $settings['adminPermission'] = $row['AdminPermission'] ?: null;
            $settings['adminDelivery'] = $row['AdminDelivery'] === 'daily' ? 'daily' : 'immediate';
            $settings['locked'] = (int) $row['Locked'] === 1;
            $settings['person'] = ['applies' => (int) $row['EmailPersonApplies'] === 1, 'enabled' => (int) $row['EmailPersonEnabled'] === 1];
            $settings['organiser'] = ['applies' => (int) $row['EmailOrganiserApplies'] === 1, 'enabled' => (int) $row['EmailOrganiserEnabled'] === 1];
            $settings['admins'] = ['applies' => (int) $row['EmailAdminsApplies'] === 1, 'enabled' => (int) $row['EmailAdminsEnabled'] === 1];
            $settings['news'] = ['applies' => (int) $row['NewsPostApplies'] === 1, 'enabled' => (int) $row['NewsPostEnabled'] === 1];
        }
    } catch (PDOException $e) {
        // notifications.sql not run yet: starting values from the code
    }
    return $settings;
}

// Is a channel on? Locked events are always on where they apply.
function kbfNotifyChannelOn(array $settings, $channel) {
    $c = $settings[$channel];
    return $c['applies'] && ($c['enabled'] || $settings['locked']);
}

// ============================================
// PEOPLE
// ============================================
function kbfNotifyPerson($pdo, $who) {
    if (is_array($who)) {
        $first = trim((string) ($who['firstName'] ?? ''));
        $last = trim((string) ($who['lastName'] ?? ''));
        return [
            'userId' => isset($who['userId']) ? (int) $who['userId'] : null,
            'email' => trim((string) ($who['email'] ?? '')),
            'firstName' => $first,
            'fullName' => trim($first . ' ' . $last),
        ];
    }
    $userId = (int) $who;
    if ($userId < 1) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT UserId, Email, FirstName, LastName FROM Users WHERE UserId = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'userId' => (int) $row['UserId'],
        'email' => trim((string) $row['Email']),
        'firstName' => trim((string) $row['FirstName']),
        'fullName' => trim($row['FirstName'] . ' ' . $row['LastName']),
    ];
}

// Active staff who may be chosen as admin recipients for a permission
function kbfNotifyAdminCandidates($pdo, $permission) {
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.Email, u.FirstName, u.LastName, u.IsSuperAdmin
        FROM Users u
        WHERE u.IsActive = 1
          AND u.Email IS NOT NULL AND u.Email <> ''
          AND (u.IsSuperAdmin = 1
               OR EXISTS (SELECT 1 FROM UserPermissions p WHERE p.UserId = u.UserId AND p.PermissionKey = ?))
        ORDER BY u.FirstName, u.LastName
    ");
    $stmt->execute([(string) $permission]);
    return $stmt->fetchAll();
}

// The admin recipients for an event: chosen people who still qualify, or
// Super Admins when nobody (valid) is chosen
function kbfNotifyAdminRecipients($pdo, $eventKey, $permission) {
    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT u.UserId, u.Email, u.FirstName, u.LastName
            FROM NotificationRecipients r
            JOIN Users u ON u.UserId = r.UserId
            WHERE r.EventKey = ?
              AND u.IsActive = 1
              AND u.Email IS NOT NULL AND u.Email <> ''
              AND (u.IsSuperAdmin = 1
                   OR EXISTS (SELECT 1 FROM UserPermissions p WHERE p.UserId = u.UserId AND p.PermissionKey = ?))
        ");
        $stmt->execute([$eventKey, (string) $permission]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        $rows = [];
    }
    if (!$rows) {
        $stmt = $pdo->query("
            SELECT UserId, Email, FirstName, LastName
            FROM Users
            WHERE IsActive = 1 AND IsSuperAdmin = 1 AND Email IS NOT NULL AND Email <> ''
        ");
        $rows = $stmt->fetchAll();
    }
    return array_map(function ($r) {
        return [
            'userId' => (int) $r['UserId'],
            'email' => trim((string) $r['Email']),
            'firstName' => trim((string) $r['FirstName']),
            'fullName' => trim($r['FirstName'] . ' ' . $r['LastName']),
        ];
    }, $rows);
}

// ============================================
// BUILDING AN EMAIL
// ============================================

// Finished email from a subject and inner HTML: ['subject', 'html', 'text']
function kbfBuildEmail($subject, $bodyHtml, array $values) {
    $rendered = kbfRenderTemplate($subject, $bodyHtml, $values);
    $text = kbfHtmlToText($rendered['html']) . "\n\n---\nKBF-UK | kbf-uk.org";
    return [
        'subject' => $rendered['subject'] !== '' ? $rendered['subject'] : 'KBF-UK',
        'html' => kbfEmailLayout($rendered['html']),
        'text' => $text,
    ];
}

// Values every email can use
function kbfNotifyBaseValues($pdo) {
    $site = kbfSiteLink($pdo);
    return [
        'site_link' => $site,
        'login_link' => $site . '/login',
        'whatsapp_link' => kbfWhatsappLink($pdo),
        'contact_email' => kbfContactEmail(),
        'security_email' => kbfSecurityEmail(),
    ];
}

// Where admins deal with each event
function kbfNotifyReviewLink($pdo, $eventKey) {
    $site = kbfSiteLink($pdo);
    $pages = [
        'registration_waiting' => '/admin/approvals',
        'tournament_submitted' => '/admin/approvals',
        'results_submitted' => '/admin/approvals',
        'eligible_for_promotion' => '/admin/rankings',
        'promoted' => '/admin/rankings',
    ];
    return $site . ($pages[$eventKey] ?? '/admin/dashboard');
}

// ============================================
// DEFERRAL (inside a transaction)
// ============================================
function kbfNotifyQueue($action, $item = null) {
    static $queue = [];
    if ($action === 'add') {
        $queue[] = $item;
        return null;
    }
    $items = $queue;
    $queue = [];
    return $action === 'take' ? $items : null;
}

// Send everything held back during a transaction (call after commit)
function kbfNotifyFlush() {
    $results = [];
    foreach (kbfNotifyQueue('take') as $item) {
        $results[] = notify($item[0], $item[1]);
    }
    return $results;
}

// Forget everything held back (after a rollback)
function kbfNotifyDiscard() {
    kbfNotifyQueue('clear');
}

// ============================================
// NOTIFY
// ============================================
function notify($eventKey, array $context = []) {
    global $pdo;
    $results = ['person' => 'n/a', 'organiser' => 'n/a', 'admins' => 'n/a', 'news' => 'n/a', 'errors' => []];

    try {
        if ($pdo->inTransaction()) {
            kbfNotifyQueue('add', [$eventKey, $context]);
            foreach (['person', 'organiser', 'admins', 'news'] as $c) {
                $results[$c] = 'deferred';
            }
            return $results;
        }

        $settings = kbfNotifyEventSettings($pdo, $eventKey);
        if ($settings === null) {
            error_log('notify: unknown event ' . $eventKey);
            return $results;
        }
        $def = KBF_NOTIFY_EVENTS[$eventKey];

        // Values: base, then the event's own
        $values = kbfNotifyBaseValues($pdo);
        $values['review_link'] = kbfNotifyReviewLink($pdo, $eventKey);
        foreach (($context['values'] ?? []) as $name => $value) {
            $values[$name] = $value === null ? '' : (string) $value;
        }

        // The person the event is about fills {first_name} and {full_name}
        $person = isset($context['person']) ? kbfNotifyPerson($pdo, $context['person']) : null;
        $organiser = isset($context['organiser']) ? kbfNotifyPerson($pdo, $context['organiser']) : null;
        $about = $person ?: $organiser;
        if ($about) {
            if (!isset($values['first_name'])) { $values['first_name'] = $about['firstName']; }
            if (!isset($values['full_name'])) { $values['full_name'] = $about['fullName']; }
        }

        // Secrets are replaced before anything is stored
        $redact = [];
        foreach (($context['secrets'] ?? []) as $name) {
            if (isset($values[$name]) && $values[$name] !== '') {
                $redact[$values[$name]] = '[' . str_replace('_', ' ', $name) . ']';
                $redact[htmlspecialchars($values[$name], ENT_QUOTES, 'UTF-8')] = '[' . str_replace('_', ' ', $name) . ']';
            }
        }

        $sentTo = [];
        $common = [
            'eventKey' => $eventKey,
            'priority' => $settings['priority'],
            'relatedType' => $context['relatedType'] ?? null,
            'relatedId' => $context['relatedId'] ?? null,
            'redact' => $redact,
        ];

        // ---------- person and organiser ----------
        foreach (['person' => $person, 'organiser' => $organiser] as $audience => $who) {
            if (!$settings[$audience]['applies']) {
                continue;
            }
            // 'forcePerson' => true: an admin chose to send this email (for
            // example "send their KBF ID" when adding a player), so the
            // person's email goes even when the event is switched off.
            // 'forceOrganiser' => true does the same for the organiser
            // (for example "Remind the organiser" on the Tournaments page).
            $forced = ($audience === 'person' && !empty($context['forcePerson']))
                || ($audience === 'organiser' && !empty($context['forceOrganiser']));
            // 'skipPerson' => true: an admin chose not to send it (for example
            // the "Email them" tick on the Grading page); a locked event still sends
            $skipped = $audience === 'person' && !empty($context['skipPerson']) && !$settings['locked'];
            if ($skipped || (!$forced && !kbfNotifyChannelOn($settings, $audience))) {
                $results[$audience] = 'off';
                continue;
            }
            if (!$who || $who['email'] === '' || !filter_var($who['email'], FILTER_VALIDATE_EMAIL)) {
                $results[$audience] = 'none';
                continue;
            }
            $lower = strtolower($who['email']);
            if (isset($sentTo[$lower])) {
                $results[$audience] = $sentTo[$lower];
                continue;
            }
            $status = kbfNotifySendOne($pdo, $eventKey, $audience, $who, $values, $common);
            $sentTo[$lower] = $status;
            $results[$audience] = $status;
            if ($status !== 'sent') {
                $results['errors'][$audience] = kbfMailLastError();
            }
        }

        // ---------- admins ----------
        if ($settings['admins']['applies']) {
            if (!kbfNotifyChannelOn($settings, 'admins')) {
                $results['admins'] = 'off';
            } else {
                $counts = ['sent' => 0, 'waiting' => 0, 'failed' => 0, 'held' => 0];
                $recipients = kbfNotifyAdminRecipients($pdo, $eventKey, $settings['adminPermission']);
                foreach ($recipients as $admin) {
                    $lower = strtolower($admin['email']);
                    if ($admin['email'] === '' || isset($sentTo[$lower]) || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    if ($settings['adminDelivery'] === 'daily') {
                        if (kbfNotifyHoldForDigest($pdo, $eventKey, $admin, $values)) {
                            $counts['held']++;
                            $sentTo[$lower] = 'held';
                        }
                        continue;
                    }
                    $status = kbfNotifySendOne($pdo, $eventKey, 'admins', $admin, $values, $common);
                    $sentTo[$lower] = $status;
                    $counts[$status] = ($counts[$status] ?? 0) + 1;
                }
                $results['admins'] = $recipients ? $counts : 'none';
            }
        }

        // ---------- news post ----------
        if ($settings['news']['applies'] && isset($context['news']) && is_array($context['news'])) {
            $news = $context['news'];
            $create = array_key_exists('create', $news) && $news['create'] !== null
                ? (bool) $news['create']
                : $settings['news']['enabled'];
            if (!$create) {
                $results['news'] = 'off';
            } else {
                $results['news'] = kbfNotifyNewsPost($pdo, $news);
            }
        }
    } catch (\Throwable $e) {
        error_log('notify(' . $eventKey . ') error: ' . $e->getMessage());
        $results['errors']['notify'] = 'An unexpected error stopped the notification.';
    }

    return $results;
}

// Build and send one email of an event to one recipient
function kbfNotifySendOne($pdo, $eventKey, $audience, array $who, array $values, array $common) {
    $templateKey = kbfTemplateKey($eventKey, $audience);
    $template = kbfGetTemplate($pdo, $templateKey);
    $email = kbfBuildEmail($template['subject'], $template['bodyHtml'], $values);

    $attachments = array_map(function ($a) {
        return ['file' => $a['file'], 'name' => $a['name']];
    }, kbfTemplateAttachments($pdo, $templateKey));

    return kbfSendEmail(array_merge($common, [
        'toEmail' => $who['email'],
        'toName' => $who['fullName'] !== '' ? $who['fullName'] : $who['firstName'],
        'subject' => $email['subject'],
        'html' => $email['html'],
        'text' => $email['text'],
        'attachments' => $attachments,
    ]));
}

// Hold an admin email for the nightly summary. Never used for secrets.
function kbfNotifyHoldForDigest($pdo, $eventKey, array $admin, array $values) {
    $templateKey = kbfTemplateKey($eventKey, 'admins');
    $template = kbfGetTemplate($pdo, $templateKey);
    $rendered = kbfRenderTemplate($template['subject'], $template['bodyHtml'], $values);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO NotificationDigest (EventKey, UserId, Subject, BodyText, CreatedDate)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $eventKey,
            $admin['userId'],
            kbfMailCut($rendered['subject'], 255),
            kbfHtmlToText($rendered['html']),
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('notify: could not hold digest item for ' . $eventKey . ': ' . $e->getMessage());
        return false;
    }
}

// Create a published news post. Returns the post id or 'failed'.
function kbfNotifyNewsPost($pdo, array $news) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO Announcements
            (Title, Content, CreatedByAdminId, FeaturedImage, TournamentId, CreatedDate, PublishedDate, IsPublished, IsVisible)
            VALUES (?, ?, ?, ?, NULL, NOW(), NOW(), 1, 1)
        ");
        $stmt->execute([
            (string) ($news['title'] ?? ''),
            (string) ($news['content'] ?? ''),
            (int) ($news['authorId'] ?? 0),
            !empty($news['image']) ? (string) $news['image'] : null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('notify: news post failed: ' . $e->getMessage());
        return 'failed';
    }
}

// ============================================
// DAILY SUMMARIES (nightly ranking-calculator job)
// One email per person per event listing everything held since the last
// summary; the rows included are then cleared.
// Returns ['sent' => n, 'waiting' => n, 'failed' => n]
// ============================================
function kbfNotifySendDigests($pdo) {
    $counts = ['sent' => 0, 'waiting' => 0, 'failed' => 0];
    $groups = $pdo->query("
        SELECT EventKey, UserId, MAX(Id) AS MaxId
        FROM NotificationDigest
        GROUP BY EventKey, UserId
    ")->fetchAll();

    $itemsStmt = $pdo->prepare("
        SELECT Subject, BodyText, CreatedDate FROM NotificationDigest
        WHERE EventKey = ? AND UserId = ? AND Id <= ?
        ORDER BY Id
    ");
    $clearStmt = $pdo->prepare("DELETE FROM NotificationDigest WHERE EventKey = ? AND UserId = ? AND Id <= ?");
    $userStmt = $pdo->prepare("SELECT Email, FirstName, LastName FROM Users WHERE UserId = ? AND IsActive = 1");

    foreach ($groups as $g) {
        $userStmt->execute([(int) $g['UserId']]);
        $user = $userStmt->fetch();
        if (!$user || !filter_var(trim((string) $user['Email']), FILTER_VALIDATE_EMAIL)) {
            // Nobody to send to any more
            $clearStmt->execute([$g['EventKey'], (int) $g['UserId'], (int) $g['MaxId']]);
            continue;
        }
        $itemsStmt->execute([$g['EventKey'], (int) $g['UserId'], (int) $g['MaxId']]);
        $items = $itemsStmt->fetchAll();
        if (!$items) {
            continue;
        }

        $label = KBF_NOTIFY_EVENTS[$g['EventKey']]['label'] ?? $g['EventKey'];
        $html = '<h2>Daily summary</h2><p>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': '
            . count($items) . ' since the last summary.</p>';
        foreach ($items as $item) {
            $html .= '<h3>' . htmlspecialchars($item['Subject'], ENT_QUOTES, 'UTF-8') . '</h3>'
                . '<p>' . nl2br(htmlspecialchars((string) $item['BodyText'], ENT_QUOTES, 'UTF-8'), false) . '</p>'
                . '<p>' . htmlspecialchars(date('j M Y, H:i', strtotime($item['CreatedDate'])), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $status = kbfSendEmail([
            'eventKey' => 'daily_summary',
            'priority' => 'normal',
            'toEmail' => trim((string) $user['Email']),
            'toName' => trim($user['FirstName'] . ' ' . $user['LastName']),
            'subject' => 'Daily summary: ' . $label . ' (' . count($items) . ')',
            'html' => kbfEmailLayout($html),
            'text' => kbfHtmlToText($html) . "\n\n---\nKBF-UK | kbf-uk.org",
            'relatedType' => 'digest',
        ]);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
        if ($status !== 'failed') {
            $clearStmt->execute([$g['EventKey'], (int) $g['UserId'], (int) $g['MaxId']]);
        }
    }
    return $counts;
}

// ============================================
// MESSAGES FOR ADMINS
// "The email has been sent." for one audience's result
// ============================================
function kbfNotifyOutcomeText(array $results, $audience, $what = 'The email') {
    $status = $results[$audience] ?? 'n/a';
    if ($status === 'sent') {
        return $what . ' has been sent.';
    }
    if ($status === 'waiting') {
        return $what . ' will go out when the daily allowance frees up.';
    }
    if ($status === 'off') {
        return $what . ' is switched off.';
    }
    if ($status === 'none') {
        return 'There is no email address to send ' . strtolower($what) . ' to.';
    }
    if ($status === 'failed') {
        $reason = rtrim(trim((string) ($results['errors'][$audience] ?? '')), '.');
        return $what . ' could not be sent' . ($reason !== '' ? ': ' . $reason : '') . '.';
    }
    if (!empty($results['errors']['notify'])) {
        return $what . ' could not be sent: ' . rtrim($results['errors']['notify'], '.') . '.';
    }
    return '';
}
?>
