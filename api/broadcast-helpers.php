<?php
// ============================================
// KBF-UK: SHARED BROADCAST RULES
// Used by api/broadcasts.php and jobs/broadcast-sender.php, so the page,
// the count and the job always agree.
//
// Targets (EmailBroadcasts.TargetType):
//   all     every member
//   grades  members in the chosen grades (TargetGrades, comma-separated ids)
//   staff   Super Admins and anyone holding a permission
//   An empty TargetType is the old target: one grade (TargetCategoryId)
//   when it is set, otherwise everyone. 'category' is the old one-grade
//   target and is read the same way.
//
// A recipient is an active, not removed member with an email address who
// has not unsubscribed (EmailOptOut = 0).
//
// Broadcast emails per day = email_daily_limit minus email_reserve_important
// (the bulk share of the daily allowance in api/mailer.php).
//
// Scheduled times are UK time (Europe/London). They are stored relative to
// the database's own clock (NOW() + seconds), so the job's
// "ScheduledFor <= NOW()" is right whatever time zone the server uses.
// ============================================

require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email-templates.php';

const KBF_BROADCAST_TZ = 'Europe/London';
const KBF_BROADCAST_MIN_AHEAD = 600;          // 10 minutes
const KBF_BROADCAST_MAX_AHEAD = 60 * 86400;   // 60 days
const KBF_BROADCAST_PLACEHOLDERS = ['first_name', 'full_name', 'kbf_id', 'grade', 'site_link', 'contact_email', 'whatsapp_link'];

// True when database/grading-broadcasts.sql has been run
function kbfBroadcastSchemaReady($pdo) {
    static $ready = null;
    if ($ready === null) {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailBroadcasts'
                  AND COLUMN_NAME IN ('TargetGrades', 'ScheduledFor', 'UpdatedDate', 'CancelledDate')
            ");
            $ready = (int) $stmt->fetchColumn() === 4;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

// "3,5" -> [3, 5]
function kbfBroadcastGradeIds($raw) {
    $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
    $ids = [];
    foreach ($parts as $p) {
        $id = (int) trim((string) $p);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    return array_keys($ids);
}

// The target of a stored broadcast: ['type' => all|grades|staff, 'grades' => [ids]]
function kbfBroadcastTargetOf(array $row) {
    $type = strtolower(trim((string) ($row['TargetType'] ?? '')));
    if ($type === 'grades') {
        return ['type' => 'grades', 'grades' => kbfBroadcastGradeIds($row['TargetGrades'] ?? '')];
    }
    if ($type === 'staff') {
        return ['type' => 'staff', 'grades' => []];
    }
    if ($type === 'all') {
        return ['type' => 'all', 'grades' => []];
    }
    // Old target: one grade when TargetCategoryId is set, otherwise everyone
    $catId = (int) ($row['TargetCategoryId'] ?? 0);
    return $catId > 0 ? ['type' => 'grades', 'grades' => [$catId]] : ['type' => 'all', 'grades' => []];
}

// WHERE fragment and parameters for a target, on Users aliased "u"
function kbfBroadcastTargetWhere($type, array $gradeIds) {
    $where = "u.IsActive = 1 AND COALESCE(u.IsRemoved, 0) = 0 AND u.Email IS NOT NULL AND u.Email <> ''";
    $params = [];
    if ($type === 'grades') {
        if (!$gradeIds) {
            $where .= ' AND 1 = 0';
        } else {
            $where .= ' AND u.CategoryId IN (' . implode(',', array_fill(0, count($gradeIds), '?')) . ')';
            foreach ($gradeIds as $id) {
                $params[] = (int) $id;
            }
        }
    } elseif ($type === 'staff') {
        $where .= ' AND (u.IsSuperAdmin = 1 OR EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId))';
    }
    return [$where, $params];
}

// ['recipients' => n, 'unsubscribed' => n] for a target
function kbfBroadcastCounts($pdo, $type, array $gradeIds) {
    list($where, $params) = kbfBroadcastTargetWhere($type, $gradeIds);
    $stmt = $pdo->prepare("
        SELECT SUM(CASE WHEN COALESCE(u.EmailOptOut, 0) = 0 THEN 1 ELSE 0 END) AS Recipients,
               SUM(CASE WHEN COALESCE(u.EmailOptOut, 0) = 1 THEN 1 ELSE 0 END) AS Unsubscribed
        FROM Users u
        WHERE $where
    ");
    $stmt->execute($params);
    $row = $stmt->fetch();
    return [
        'recipients' => (int) ($row['Recipients'] ?? 0),
        'unsubscribed' => (int) ($row['Unsubscribed'] ?? 0),
    ];
}

// Broadcast emails per day (the daily limit minus the important reserve)
function kbfBroadcastPerDay($pdo) {
    $s = kbfMailSettings($pdo);
    return max(0, kbfMailCap('bulk', $s['limit'], $s['reserve']));
}

// Days needed to send $count emails at $perDay a day (0 when nothing to send)
function kbfBroadcastDays($count, $perDay) {
    if ($count < 1) {
        return 0;
    }
    if ($perDay < 1) {
        return null;
    }
    return (int) ceil($count / $perDay);
}

// Fill the queue for a broadcast with the target's current recipients and
// set TotalRecipients. Call inside a transaction. Returns the number queued.
function kbfBroadcastFillQueue($pdo, $broadcastId, $type, array $gradeIds) {
    list($where, $params) = kbfBroadcastTargetWhere($type, $gradeIds);
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.Email, u.FirstName
        FROM Users u
        WHERE $where AND COALESCE(u.EmailOptOut, 0) = 0
        ORDER BY u.UserId ASC
    ");
    $stmt->execute($params);
    $insert = $pdo->prepare("
        INSERT INTO EmailQueue (BroadcastId, UserId, Email, RecipientName, Status, Attempts)
        VALUES (?, ?, ?, ?, 'pending', 0)
    ");
    $count = 0;
    while ($r = $stmt->fetch()) {
        $insert->execute([(int) $broadcastId, (int) $r['UserId'], $r['Email'], $r['FirstName']]);
        $count++;
    }
    $stmt = $pdo->prepare("UPDATE EmailBroadcasts SET TotalRecipients = ? WHERE BroadcastId = ?");
    $stmt->execute([$count, (int) $broadcastId]);
    return $count;
}

// Placeholder values for one recipient (plain text; escaped when filled in)
function kbfBroadcastValues($pdo, array $person) {
    $first = trim(html_entity_decode((string) ($person['FirstName'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $last = trim(html_entity_decode((string) ($person['LastName'] ?? ''), ENT_QUOTES, 'UTF-8'));
    return [
        'first_name' => $first,
        'full_name' => trim($first . ' ' . $last),
        'kbf_id' => (string) ($person['PlayerId'] ?? ''),
        'grade' => html_entity_decode((string) ($person['GradeShort'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'site_link' => kbfSiteLink($pdo),
        'contact_email' => kbfContactEmail(),
        'whatsapp_link' => kbfWhatsappLink($pdo),
    ];
}

// Subject (plain) and body with one recipient's placeholders filled in.
// Stored subjects are HTML-escaped (sanitizeInput), so they are decoded first.
function kbfBroadcastRender($pdo, $subject, $bodyHtml, array $person) {
    $plainSubject = html_entity_decode((string) $subject, ENT_QUOTES, 'UTF-8');
    return kbfRenderTemplate($plainSubject, (string) $bodyHtml, kbfBroadcastValues($pdo, $person));
}

// ============================================
// SCHEDULING (UK time)
// ============================================

// 'YYYY-MM-DDTHH:MM' (UK time) -> Unix timestamp, or null when invalid
function kbfBroadcastParseLocal($value) {
    $value = trim((string) $value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/', $value, $m)) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
        return null;
    }
    try {
        $dt = new DateTime(sprintf('%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4], $m[5]), new DateTimeZone(KBF_BROADCAST_TZ));
    } catch (Exception $e) {
        return null;
    }
    return $dt->getTimestamp();
}

// Timestamp -> 'Saturday 5 December 2026 at 18:30' (UK time)
function kbfBroadcastLabel($ts) {
    $dt = new DateTime('@' . (int) $ts);
    $dt->setTimezone(new DateTimeZone(KBF_BROADCAST_TZ));
    return $dt->format('l j F Y \a\t H:i');
}

// Timestamp -> 'YYYY-MM-DDTHH:MM' (UK time, for a datetime-local field)
function kbfBroadcastLocalValue($ts) {
    $dt = new DateTime('@' . (int) $ts);
    $dt->setTimezone(new DateTimeZone(KBF_BROADCAST_TZ));
    return $dt->format('Y-m-d\TH:i');
}
?>
