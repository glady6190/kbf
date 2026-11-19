<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }

require_once __DIR__ . '/image-helpers.php';

// ============================================
// DATA RETENTION, ERASURE AND UNUSED FILES (QUAL-08, QUAL-09, SEC-15)
//
//   Settings (Security page, "Data retention", Super Admins only; the code
//   defaults below apply until a Settings row exists):
//     retention_ip_days                     90   IP addresses blanked after this
//     retention_email_body_days             30   sent/failed email text cleared
//     retention_rejected_registration_days  365  rejected applications cleared
//     file_cleanup_mode                     report | delete
//   The activity log period stays audit_log_retention_days (730).
//
//   Used by jobs/ranking-calculator.php (nightly), api/approvals.php (after an
//   approval is saved) and api/people.php ("Remove at their request").
// ============================================

// Setting key => [label, minimum, maximum, default]
const KBF_RETENTION_RULES = [
    'ipDays' => ['retention_ip_days', 'Keep internet (IP) addresses for', 7, 3650, 90],
    'emailBodyDays' => ['retention_email_body_days', 'Keep the text of sent emails for', 7, 3650, 30],
    'rejectedDays' => ['retention_rejected_registration_days', 'Keep rejected applications for', 30, 3650, 365],
];
const KBF_FILE_CLEANUP_MODES = ['report', 'delete'];

// Folders the unused-file clean-up looks at. Never tournaments (posters),
// gallery, sponsors, broadcast-files or anything in storage/.
const KBF_FILE_CLEANUP_FOLDERS = ['profiles', 'registrations', 'announcements'];

// At most this many unused images (with their versions) deleted per night
const KBF_FILE_CLEANUP_MAX_DELETE = 1000;

const KBF_REGISTRATION_PHOTO_PATTERN = '#^/uploads/registrations/[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$#';

// The retention settings in force now
function kbfRetentionNow() {
    $out = [];
    foreach (KBF_RETENTION_RULES as $field => $r) {
        $out[$field] = kbfSettingInt($r[0], $r[4], $r[2], $r[3]);
    }
    $out['fileCleanupMode'] = kbfFileCleanupMode();
    return $out;
}

// "report" (only list what would be deleted) unless set to "delete"
function kbfFileCleanupMode() {
    $mode = strtolower(trim((string) kbfSetting('file_cleanup_mode', 'report')));
    return in_array($mode, KBF_FILE_CLEANUP_MODES, true) ? $mode : 'report';
}

// ============================================
// REGISTRATIONS
// ============================================

// Delete a registration photo (and its versions) when no member or
// registration row refers to it any more. Returns true when a file went.
function kbfDeleteRegistrationPhotoIfUnused(PDO $pdo, $path) {
    if (!is_string($path) || !preg_match(KBF_REGISTRATION_PHOTO_PATTERN, $path)) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT (SELECT COUNT(*) FROM Users WHERE ProfilePhoto = ? OR ProfilePicture = ?)
             + (SELECT COUNT(*) FROM PlayerRegistrations WHERE ProfilePhoto = ?)
    ");
    $stmt->execute([$path, $path, $path]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }
    $existed = is_file(dirname(__DIR__) . $path);
    kbfDeleteStoredImage($path, '/uploads/registrations');
    return $existed;
}

// Approved registrations: the member's account now holds the details, so the
// registration keeps only what the decision record and reports need (name,
// town, grade, KBF ID, dates, decision and comments). Email, phone, post code,
// year of birth, photo path and IP address are cleared, and the registration
// photo file goes when nothing else refers to it.
// $ids: these registrations only, or null for every approved one still
// holding any of those details. Returns [rows cleared, files deleted].
function kbfClearApprovedRegistrations(PDO $pdo, $ids = null) {
    $where = "Status = 'Approved'
        AND (Email <> '' OR COALESCE(PhoneNumber, '') <> '' OR COALESCE(PostCode, '') <> ''
             OR YearOfBirth IS NOT NULL OR ProfilePhoto IS NOT NULL OR COALESCE(SubmittedIpAddress, '') <> '')";
    $params = [];
    if ($ids !== null) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if (!$ids) {
            return [0, 0];
        }
        $where .= ' AND RegistrationId IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
    }
    $stmt = $pdo->prepare("SELECT RegistrationId, ProfilePhoto FROM PlayerRegistrations WHERE $where");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return [0, 0];
    }
    $update = $pdo->prepare("
        UPDATE PlayerRegistrations
        SET Email = '', PhoneNumber = NULL, PostCode = NULL, YearOfBirth = NULL,
            ProfilePhoto = NULL, SubmittedIpAddress = NULL
        WHERE RegistrationId = ? AND Status = 'Approved'
    ");
    $cleared = 0;
    foreach ($rows as $r) {
        $update->execute([(int) $r['RegistrationId']]);
        $cleared += $update->rowCount();
    }
    // Files only after the rows no longer point at them
    $files = 0;
    foreach ($rows as $r) {
        if (kbfDeleteRegistrationPhotoIfUnused($pdo, $r['ProfilePhoto'])) {
            $files++;
        }
    }
    return [$cleared, $files];
}

// Rejected registrations decided more than $days ago: personal details
// cleared (as "Remove at their request" does for registration rows), the
// approval history names them "Former applicant", their name, email and phone
// are replaced in the activity log and email log rows about that registration,
// and the photo file goes. The row itself is kept for counts.
// Up to $limit rows per run, one transaction each. Returns [rows, files].
function kbfClearRejectedRegistrations(PDO $pdo, $days, $limit = 500) {
    $stmt = $pdo->prepare("
        SELECT RegistrationId, FirstName, LastName, Email, PhoneNumber, Location, ProfilePhoto
        FROM PlayerRegistrations
        WHERE Status = 'Rejected'
          AND COALESCE(ReviewedDate, SubmittedDate) < DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (Email NOT LIKE '%@invalid.local' OR ProfilePhoto IS NOT NULL)
        ORDER BY RegistrationId
        LIMIT " . (int) $limit);
    $stmt->execute([(int) $days]);
    $rows = $stmt->fetchAll();

    $cleared = 0;
    $files = 0;
    foreach ($rows as $r) {
        $regId = (int) $r['RegistrationId'];
        $identity = [
            'firstName' => $r['FirstName'], 'lastName' => $r['LastName'], 'email' => $r['Email'],
            'phone' => $r['PhoneNumber'], 'location' => $r['Location'],
        ];
        // Already cleared by "Remove at their request": only the file is left
        if (substr((string) $r['Email'], -strlen('@invalid.local')) === '@invalid.local') {
            $identity = [];
        }
        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare("
                UPDATE PlayerRegistrations
                SET FirstName = 'Former', LastName = 'applicant',
                    Email = CONCAT('removed-registration-', RegistrationId, '@invalid.local'),
                    PhoneNumber = NULL, PostCode = NULL, YearOfBirth = NULL, Location = '',
                    ProfilePhoto = NULL, SubmittedIpAddress = NULL
                WHERE RegistrationId = ? AND Status = 'Rejected'
            ");
            $upd->execute([$regId]);
            if ($identity) {
                $pdo->prepare("
                    UPDATE ApprovalLogs SET ReferenceName = 'Former applicant', ReferenceDetails = 'Details removed after the keeping period'
                    WHERE LogType = 'Registration' AND ReferenceId = ?
                ")->execute([$regId]);
                // Rows about this registration only (keyed): name, email, phone and town
                $rules = kbfErasureRules([$identity], 'Former applicant', 'keyed');
                kbfScrubRows($pdo, 'AuditLog', 'AuditId', ['Details'],
                    "TargetType = 'registration' AND TargetId = ?", [$regId], $rules);
                kbfScrubRows($pdo, 'EmailLog', 'Id', ['ToEmail', 'ToName', 'Subject', 'BodyHtml', 'BodyText'],
                    "RelatedType = 'registration' AND RelatedId = ?", [$regId], $rules);
                kbfScrubRows($pdo, 'ApprovalLogs', 'LogId', ['Comments'],
                    "LogType = 'Registration' AND ReferenceId = ?", [$regId], $rules);
            }
            $pdo->commit();
            $cleared++;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('data-retention: rejected registration ' . $regId . ' not cleared: ' . $e->getMessage());
            continue;
        }
        if (kbfDeleteRegistrationPhotoIfUnused($pdo, $r['ProfilePhoto'])) {
            $files++;
        }
    }
    return [$cleared, $files];
}

// ============================================
// NIGHTLY RETENTION (jobs/ranking-calculator.php)
// Each step on its own: a problem in one never stops the others.
// Returns a list of short summary parts (empty when nothing changed).
// ============================================
function kbfApplyDataRetention(PDO $pdo) {
    $now = kbfRetentionNow();
    $parts = [];

    // 1. IP addresses older than retention_ip_days (rows kept)
    $ipSteps = [
        "UPDATE AuditLog SET IpAddress = '' WHERE IpAddress <> '' AND CreatedDate < DATE_SUB(NOW(), INTERVAL ? DAY)",
        "UPDATE LoginAttempts SET IpAddress = '' WHERE IpAddress <> '' AND AttemptTime < DATE_SUB(NOW(), INTERVAL ? DAY)",
        "UPDATE PasswordResets SET IpAddress = NULL WHERE IpAddress IS NOT NULL AND CreatedAt < DATE_SUB(NOW(), INTERVAL ? DAY)",
        "UPDATE PlayerRegistrations SET SubmittedIpAddress = NULL
         WHERE SubmittedIpAddress IS NOT NULL AND SubmittedDate < DATE_SUB(NOW(), INTERVAL ? DAY)",
    ];
    $ips = 0;
    foreach ($ipSteps as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$now['ipDays']]);
            $ips += $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('data-retention: IP address step failed: ' . $e->getMessage());
        }
    }
    if ($ips > 0) {
        $parts[] = $ips . ' IP addresses blanked';
    }

    // 2. Email text (sent or failed, never waiting) older than retention_email_body_days
    try {
        $stmt = $pdo->prepare("
            UPDATE EmailLog SET BodyHtml = NULL, BodyText = NULL
            WHERE Status IN ('sent', 'failed')
              AND (BodyHtml IS NOT NULL OR BodyText IS NOT NULL)
              AND COALESCE(SentDate, CreatedDate) < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$now['emailBodyDays']]);
        if ($stmt->rowCount() > 0) {
            $parts[] = $stmt->rowCount() . ' email texts cleared';
        }
    } catch (PDOException $e) {
        error_log('data-retention: email text step failed: ' . $e->getMessage());
    }

    // 3. Rejected applications older than retention_rejected_registration_days
    try {
        list($rows, $files) = kbfClearRejectedRegistrations($pdo, $now['rejectedDays']);
        if ($rows + $files > 0) {
            $parts[] = $rows . ' rejected applications cleared (' . $files . ' photos)';
        }
    } catch (PDOException $e) {
        error_log('data-retention: rejected applications step failed: ' . $e->getMessage());
    }

    // 4. Approved applications: details now held on the member's account
    try {
        list($rows, $files) = kbfClearApprovedRegistrations($pdo, null);
        if ($rows + $files > 0) {
            $parts[] = $rows . ' approved applications cleared (' . $files . ' photos)';
        }
    } catch (PDOException $e) {
        error_log('data-retention: approved applications step failed: ' . $e->getMessage());
    }

    return $parts;
}

// ============================================
// ERASURE IN STORED TEXT ("Remove at their request", rejected applications)
//
// MATCHING RULE (so nobody else's data is ever changed):
//  * Rows linked to the person by a key (AuditLog TargetType user/registration
//    + TargetId, EmailLog RelatedType + RelatedId or sent to their address,
//    UserActivityLogs.TargetUserId, ApprovalLogs registration ReferenceId) are
//    "keyed": in them their full name, email, phone number and town are
//    replaced. Only the person's own values are replaced, so another name in
//    the same row stays.
//  * Emails sent to their own address also have the first name alone replaced
//    in the subject ("Welcome, Sam"), and their text cleared.
//  * Every other row ("global") is searched only for the exact full name and
//    the email address, and only when they are unambiguous: no other member or
//    applicant has a full name containing that full name (so "Ann Lee" is left
//    alone when "Mary Ann Lee" exists) and no other member or applicant has
//    that email. Phone numbers and towns are never replaced globally (families
//    share phones; towns are not personal).
//  * A name matches as a whole phrase (not inside a longer word), case
//    insensitive, as typed or HTML-encoded (O'Neil, O&#039;Neil, O&#39;Neil,
//    O&apos;Neil). An email matches as a whole address.
// ============================================

// Stored text may be HTML-encoded (older rows): plain text
function kbfErasurePlain($text) {
    $text = html_entity_decode(trim((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

// The forms a piece of text may be stored in (plain and HTML-encoded)
function kbfErasureForms($plain) {
    $encoded = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
    return array_values(array_unique([
        $plain,
        $encoded,
        str_replace('&#039;', '&#39;', $encoded),
        str_replace('&#039;', '&apos;', $encoded),
    ]));
}

function kbfErasureLength($text) {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

// Replacement rules [pattern, replacement] for a list of identities
// (firstName, lastName, email, phone, location; any may be empty).
// $scope: 'global' (full name and email), 'keyed' (also phone and town),
// 'own' (keyed plus the first name alone).
function kbfErasureRules(array $identities, $label, $scope) {
    $rules = [];
    $firstNames = [];
    foreach ($identities as $who) {
        $first = kbfErasurePlain($who['firstName'] ?? '');
        $last = kbfErasurePlain($who['lastName'] ?? '');
        $full = trim($first . ' ' . $last);
        if ($first !== '' && $last !== '' && kbfErasureLength($full) >= 3) {
            foreach (kbfErasureForms($full) as $form) {
                $rules[] = ['/(?<![\p{L}\p{N}])' . preg_quote($form, '/') . '(?![\p{L}\p{N}])/iu', $label];
            }
        }
        if ($first !== '' && kbfErasureLength($first) >= 2) {
            $firstNames[] = $first;
        }
        $email = trim((string) ($who['email'] ?? ''));
        if ($email !== '' && strpos($email, '@') !== false && substr($email, -strlen('@invalid.local')) !== '@invalid.local') {
            foreach (kbfErasureForms($email) as $form) {
                $rules[] = ['/(?<![\w.%+\-])' . preg_quote($form, '/') . '(?![\w\-]|\.\w)/iu', 'removed'];
            }
        }
        if ($scope === 'global') {
            continue;
        }
        $digits = preg_replace('/\D+/', '', (string) ($who['phone'] ?? ''));
        if (strlen($digits) >= 6) {
            $rules[] = ['/(?<!\d)' . implode('[\s.\-]*', str_split($digits)) . '(?!\d)/u', 'removed'];
        }
        $town = kbfErasurePlain($who['location'] ?? '');
        if (kbfErasureLength($town) >= 3) {
            foreach (kbfErasureForms($town) as $form) {
                $rules[] = ['/(?<![\p{L}\p{N}])' . preg_quote($form, '/') . '(?![\p{L}\p{N}])/iu', 'removed'];
            }
        }
    }
    // First name alone last, after the full name has been replaced
    if ($scope === 'own') {
        foreach (array_unique($firstNames) as $first) {
            foreach (kbfErasureForms($first) as $form) {
                $rules[] = ['/(?<![\p{L}\p{N}])' . preg_quote($form, '/') . '(?![\p{L}\p{N}])/iu', $label];
            }
        }
    }
    return $rules;
}

// Apply the rules to one text (null stays null; a regex problem keeps it)
function kbfErasureApply($text, array $rules) {
    if ($text === null || $text === '') {
        return $text;
    }
    $out = (string) $text;
    foreach ($rules as $rule) {
        $next = preg_replace($rule[0], $rule[1], $out);
        if ($next !== null) {
            $out = $next;
        }
    }
    return $out;
}

// Replace the rules' matches in $cols of the rows of $table matching
// $whereSql. Table and column names come from this file only, never from
// input. Returns the number of rows changed.
function kbfScrubRows(PDO $pdo, $table, $idCol, array $cols, $whereSql, array $params, array $rules) {
    if (!$rules || !$cols) {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT $idCol, " . implode(', ', $cols) . " FROM $table WHERE $whereSql");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $changed = 0;
    foreach ($rows as $row) {
        $set = [];
        $values = [];
        foreach ($cols as $col) {
            $new = kbfErasureApply($row[$col], $rules);
            if ($new !== $row[$col]) {
                $set[] = "$col = ?";
                $values[] = $new;
            }
        }
        if ($set) {
            $values[] = $row[$idCol];
            $pdo->prepare("UPDATE $table SET " . implode(', ', $set) . " WHERE $idCol = ?")->execute($values);
            $changed++;
        }
    }
    return $changed;
}

function kbfErasureLike($text) {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text) . '%';
}

// Is this full name or email also someone else's? (other members, and
// applications other than $regIds). Any problem counts as shared.
function kbfErasureIsShared(PDO $pdo, $kind, $plain, $userId, array $regIds) {
    try {
        $regIds = $regIds ?: [0];
        $notRegs = 'RegistrationId NOT IN (' . implode(',', array_fill(0, count($regIds), '?')) . ')';
        if ($kind === 'name') {
            $forms = kbfErasureForms($plain);
            $likes = implode(' OR ', array_fill(0, count($forms), "CONCAT(TRIM(FirstName), ' ', TRIM(LastName)) LIKE ?"));
            $likeParams = array_map('kbfErasureLike', $forms);
            $sql = "SELECT (SELECT COUNT(*) FROM Users WHERE UserId <> ? AND ($likes))
                         + (SELECT COUNT(*) FROM PlayerRegistrations WHERE $notRegs AND ($likes))";
            $params = array_merge([(int) $userId], $likeParams, $regIds, $likeParams);
        } else {
            $sql = "SELECT (SELECT COUNT(*) FROM Users WHERE UserId <> ? AND Email = ?)
                         + (SELECT COUNT(*) FROM PlayerRegistrations WHERE $notRegs AND Email = ?)";
            $params = array_merge([(int) $userId, $plain], $regIds, [$plain]);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log('data-retention: shared check failed: ' . $e->getMessage());
        return true;
    }
}

// Full erasure of one member's name, email and phone in stored text, inside
// the caller's transaction (api/people.php "Remove at their request").
// $identities: the member's details and those on their registrations.
// $ownEmails: addresses whose emails are theirs (text cleared).
// Must run before the EmailLog addresses are replaced. Returns rows changed.
function kbfErasePersonText(PDO $pdo, $userId, array $regIds, array $identities, array $ownEmails, $label = 'Former member') {
    $userId = (int) $userId;
    $regIds = array_values(array_unique(array_map('intval', $regIds)));
    $changed = 0;

    $keyed = kbfErasureRules($identities, $label, 'keyed');
    $own = kbfErasureRules($identities, $label, 'own');

    // Global rules: only the full names and emails nobody else has
    $globalIds = [];
    foreach ($identities as $who) {
        $first = kbfErasurePlain($who['firstName'] ?? '');
        $last = kbfErasurePlain($who['lastName'] ?? '');
        $full = trim($first . ' ' . $last);
        $entry = [];
        if ($first !== '' && $last !== '' && !kbfErasureIsShared($pdo, 'name', $full, $userId, $regIds)) {
            $entry['firstName'] = $first;
            $entry['lastName'] = $last;
        }
        $email = trim((string) ($who['email'] ?? ''));
        if ($email !== '' && !kbfErasureIsShared($pdo, 'email', $email, $userId, $regIds)) {
            $entry['email'] = $email;
        }
        if ($entry) {
            $globalIds[] = $entry;
        }
    }
    $global = kbfErasureRules($globalIds, $label, 'global');

    $regIn = $regIds ? implode(',', array_fill(0, count($regIds), '?')) : null;

    // 1. Emails to their own address: text cleared, subject and name replaced
    foreach (array_unique(array_filter(array_map('trim', $ownEmails))) as $address) {
        $stmt = $pdo->prepare("UPDATE EmailLog SET BodyHtml = NULL, BodyText = NULL WHERE ToEmail = ? AND (BodyHtml IS NOT NULL OR BodyText IS NOT NULL)");
        $stmt->execute([$address]);
        $changed += $stmt->rowCount();
        $changed += kbfScrubRows($pdo, 'EmailLog', 'Id', ['ToName', 'Subject'], 'ToEmail = ?', [$address], $own);
    }

    // 2. Keyed rows
    $where = "(TargetType = 'user' AND TargetId = ?)" . ($regIn ? " OR (TargetType = 'registration' AND TargetId IN ($regIn))" : '');
    $changed += kbfScrubRows($pdo, 'AuditLog', 'AuditId', ['Details'], $where, array_merge([$userId], $regIds), $keyed);

    $where = "(RelatedType = 'user' AND RelatedId = ?)" . ($regIn ? " OR (RelatedType = 'registration' AND RelatedId IN ($regIn))" : '');
    $changed += kbfScrubRows($pdo, 'EmailLog', 'Id', ['ToName', 'Subject', 'BodyHtml', 'BodyText'], $where, array_merge([$userId], $regIds), $keyed);

    $changed += kbfScrubRows($pdo, 'UserActivityLogs', 'LogId', ['TargetUserName', 'TargetUserDetails', 'Comments'],
        'TargetUserId = ?', [$userId], $keyed);
    if ($regIn) {
        $changed += kbfScrubRows($pdo, 'ApprovalLogs', 'LogId', ['ReferenceName', 'ReferenceDetails', 'Comments'],
            "LogType = 'Registration' AND ReferenceId IN ($regIn)", $regIds, $keyed);
    }

    // Their own notifications, daily-summary items and reset links: deleted
    foreach (["DELETE FROM Notifications WHERE UserId = ?", "DELETE FROM NotificationDigest WHERE UserId = ?",
              "DELETE FROM PasswordResets WHERE UserId = ?"] as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $changed += $stmt->rowCount();
    }
    // Sign-in attempts with their address
    foreach (array_unique(array_filter(array_map('trim', $ownEmails))) as $address) {
        $stmt = $pdo->prepare("UPDATE LoginAttempts SET Email = 'removed', IpAddress = '' WHERE Email = ?");
        $stmt->execute([$address]);
        $changed += $stmt->rowCount();
    }

    // 3. Global: exact unambiguous full name or email anywhere else
    if ($global) {
        $needles = [];
        foreach ($globalIds as $who) {
            if (isset($who['firstName'])) {
                foreach (kbfErasureForms($who['firstName'] . ' ' . $who['lastName']) as $form) {
                    $needles[] = kbfErasureLike($form);
                }
            }
            if (isset($who['email'])) {
                $needles[] = kbfErasureLike($who['email']);
            }
        }
        $needles = array_values(array_unique($needles));
        $targets = [
            ['AuditLog', 'AuditId', ['Details']],
            ['EmailLog', 'Id', ['ToName', 'Subject', 'BodyHtml', 'BodyText']],
            ['ApprovalLogs', 'LogId', ['ReferenceName', 'ReferenceDetails', 'Comments']],
            ['UserActivityLogs', 'LogId', ['TargetUserName', 'TargetUserDetails', 'Comments']],
            ['Notifications', 'NotificationId', ['Title', 'Message']],
            ['NotificationDigest', 'Id', ['Subject', 'BodyText']],
        ];
        foreach ($targets as list($table, $idCol, $cols)) {
            $or = [];
            $params = [];
            foreach ($cols as $col) {
                foreach ($needles as $n) {
                    $or[] = "$col LIKE ?";
                    $params[] = $n;
                }
            }
            $changed += kbfScrubRows($pdo, $table, $idCol, $cols, implode(' OR ', $or), $params, $global);
        }
    }

    return $changed;
}

// ============================================
// UNUSED UPLOADED FILES (SEC-15)
// A file in uploads/profiles, uploads/registrations or uploads/announcements
// is unused when no database row or stored text refers to it (the original,
// its display version or its thumbnail) and every file of the image is older
// than a day. "report" only lists them (JobRuns summary and a log file in
// storage/logs); "delete" deletes them. If any reference cannot be read,
// nothing is listed or deleted.
// ============================================

// Name without extension and without the .d / .t version suffix
function kbfUploadStem($name) {
    return preg_replace('/(\.(d|t))?\.(jpe?g|png|webp|gif)$/i', '', (string) $name);
}

// Every upload in the three folders that any row or stored text refers to,
// as "folder/stem" => true. Throws PDOException when a source cannot be read.
function kbfReferencedUploads(PDO $pdo) {
    $sources = [
        ['Users', 'ProfilePhoto'], ['Users', 'ProfilePicture'],
        ['PlayerRegistrations', 'ProfilePhoto'],
        ['Announcements', 'FeaturedImage'], ['Announcements', 'Content'],
        ['AnnouncementPhotos', 'FilePath'],
        ['EmailTemplates', 'BodyHtml'], ['EmailBroadcasts', 'BodyHtml'],
        ['EmailLog', 'BodyHtml'], ['EmailLog', 'BodyText'],
        ['NotificationDigest', 'BodyText'],
        ['Tournaments', 'Description'],
        ['Settings', 'SettingValue'], ['SystemSettings', 'SettingValue'],
        ['RequestIds', 'ResponseJson'],
    ];
    $folders = implode('|', KBF_FILE_CLEANUP_FOLDERS);
    $pattern = '#uploads\\\\?/(' . $folders . ')\\\\?/([A-Za-z0-9_.\-]+)#';
    $found = [];
    foreach ($sources as list($table, $col)) {
        $stmt = $pdo->prepare("SELECT $col FROM $table WHERE $col LIKE ?");
        $stmt->execute(['%uploads%']);
        while (($value = $stmt->fetchColumn()) !== false) {
            if ($value !== null && preg_match_all($pattern, (string) $value, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $found[$hit[1] . '/' . kbfUploadStem($hit[2])] = true;
                }
            }
        }
    }
    return $found;
}

// Returns ['mode', 'images', 'files', 'bytes', 'deleted', 'error' => string|null, 'log' => path|null]
function kbfCleanUnusedUploads(PDO $pdo, $mode) {
    $result = ['mode' => $mode === 'delete' ? 'delete' : 'report', 'images' => 0, 'files' => 0,
        'bytes' => 0, 'deleted' => 0, 'error' => null, 'log' => null];
    try {
        $referenced = kbfReferencedUploads($pdo);
    } catch (PDOException $e) {
        error_log('data-retention: file clean-up stopped, references could not be read: ' . $e->getMessage());
        $result['error'] = 'references could not be read, nothing checked';
        return $result;
    }

    $root = dirname(__DIR__) . '/uploads';
    $cutoff = time() - 86400;
    $groups = [];
    foreach (KBF_FILE_CLEANUP_FOLDERS as $folder) {
        $dir = $root . '/' . $folder;
        if (!is_dir($dir)) {
            continue;
        }
        $names = @scandir($dir);
        if ($names === false) {
            $result['error'] = 'uploads/' . $folder . ' could not be read';
            return $result;
        }
        foreach ($names as $name) {
            // Images only (never .htaccess, index files or folders)
            if (!preg_match('/^[A-Za-z0-9_\-]+(\.(d|t))?\.(jpe?g|png|webp|gif)$/i', $name)) {
                continue;
            }
            $file = $dir . '/' . $name;
            if (!is_file($file) || is_link($file)) {
                continue;
            }
            $key = $folder . '/' . kbfUploadStem($name);
            $groups[$key]['files'][] = $file;
            $groups[$key]['newest'] = max($groups[$key]['newest'] ?? 0, (int) @filemtime($file));
        }
    }

    $unused = [];
    foreach ($groups as $key => $g) {
        if (!isset($referenced[$key]) && $g['newest'] > 0 && $g['newest'] < $cutoff) {
            $unused[$key] = $g['files'];
        }
    }
    // Safety: images exist and nothing at all is referenced (a read problem)
    if ($unused && !$referenced) {
        $result['error'] = 'no references found at all, nothing deleted';
        $result['mode'] = 'report';
    }

    $lines = [];
    $deleteLeft = KBF_FILE_CLEANUP_MAX_DELETE;
    foreach ($unused as $key => $files) {
        $result['images']++;
        $doDelete = $result['mode'] === 'delete' && $deleteLeft > 0;
        if ($doDelete) {
            $deleteLeft--;
        }
        foreach ($files as $file) {
            $size = (int) @filesize($file);
            $modified = (int) @filemtime($file);
            $result['files']++;
            $result['bytes'] += $size;
            $relative = 'uploads/' . substr($file, strlen($root) + 1);
            $state = 'unused';
            if ($doDelete) {
                $state = @unlink($file) ? 'deleted' : 'delete failed';
                if ($state === 'deleted') {
                    $result['deleted']++;
                }
            }
            $lines[] = $state . "\t" . $relative . "\t" . $size . " bytes\t" . date('Y-m-d H:i', $modified);
        }
    }

    // Log file in storage/logs (blocked from the web)
    $logDir = dirname(__DIR__) . '/storage/logs';
    if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
        $logFile = $logDir . '/file-cleanup-' . date('Y-m-d') . '.log';
        $header = '# ' . date('Y-m-d H:i:s') . ' mode ' . $result['mode'] . ': ' . $result['images'] . ' unused images, '
            . $result['files'] . ' files, ' . $result['bytes'] . ' bytes, ' . $result['deleted'] . ' deleted'
            . ($result['error'] ? ' (' . $result['error'] . ')' : '');
        if (@file_put_contents($logFile, $header . "\n" . ($lines ? implode("\n", $lines) . "\n" : ''), FILE_APPEND | LOCK_EX) !== false) {
            $result['log'] = 'storage/logs/' . basename($logFile);
        }
    }
    return $result;
}
?>
