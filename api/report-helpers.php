<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
// ============================================
// KBF-UK: SHARED RULES FOR REPORTS AND DOWNLOADS
// Used by api/control-reports.php and api/control-activity.php.
//
//   CSV files   UTF-8 with a byte-order mark, dates as YYYY-MM-DD, names
//               like kbf-players-2026-11-18.csv, streamed row by row.
//               A cell starting with =, +, -, @, a tab or a carriage return
//               gets a leading apostrophe so it can never run as a formula.
//   Seasons     the shared season rules (api/grading.php) turn a season key
//               such as "2026-H2" into a date range; the season filter lists
//               only seasons that have data, newest first.
//   Actions     KBF_ACTION_NAMES: every activity log action key and its
//               friendly name; a missing key falls back to the key itself.
// ============================================

require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';

const KBF_REPORT_PAGE_SIZE = 50;

// ============================================
// CSV
// ============================================
function kbfCsvCell($value) {
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    $value = (string) $value;
    if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
        $value = "'" . $value;
    }
    return $value;
}

// "2026-11-18 14:05:00" -> "2026-11-18"
function kbfCsvDate($value) {
    if ($value === null || $value === '') {
        return '';
    }
    $ts = strtotime((string) $value);
    return $ts === false ? (string) $value : date('Y-m-d', $ts);
}

// Start a CSV download: headers, byte-order mark, header row. Returns the handle.
function kbfCsvStart($name, array $headings) {
    $safe = preg_replace('/[^a-z0-9-]+/', '-', strtolower($name));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kbf-' . $safe . '-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('kbfCsvCell', $headings));
    return $out;
}

function kbfCsvRow($out, array $cells) {
    fputcsv($out, array_map('kbfCsvCell', $cells));
}

// Run a query unbuffered so a large download is streamed, calling $each
// for every row. Returns the number of rows.
function kbfStreamRows($pdo, $sql, array $params, callable $each) {
    $count = 0;
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $each($row);
            $count++;
            if ($count % 500 === 0) {
                flush();
            }
        }
        $stmt->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    return $count;
}

// ============================================
// SEASONS
// ============================================

// "2026-H2" -> ['from' => '2026-07-01', 'to' => '2027-01-01', 'name' => ...]
// (to is exclusive). Null for an unknown key.
function kbfSeasonRange($key) {
    if (!is_string($key) || !preg_match('/^(\d{4})-H(\d{1,2})$/', $key, $m)) {
        return null;
    }
    $months = kbfSeasonSettings($GLOBALS['pdo'])['months'];
    $index = (int) $m[2] - 1;
    if ($index < 0 || $index >= count($months)) {
        return null;
    }
    $year = (int) $m[1];
    $startMonth = $months[$index];
    $season = kbfSeasonAt($months, mktime(12, 0, 0, $startMonth, 1, $year));
    return [
        'from' => sprintf('%04d-%02d-01', $year, $startMonth),
        'to' => $season['nextResetDate'],
        'name' => $season['name'],
    ];
}

// Seasons that have data in a date column, newest first.
// $table and $column are fixed strings from the calling code.
function kbfSeasonsWithData($pdo, $table, $column) {
    $months = kbfSeasonSettings($pdo)['months'];
    $seasons = [];
    try {
        $rows = $pdo->query("
            SELECT DISTINCT DATE_FORMAT($column, '%Y-%m') AS ym
            FROM $table
            WHERE $column IS NOT NULL
            ORDER BY ym DESC
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $ym) {
            if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $ym, $m)) {
                continue;
            }
            $s = kbfSeasonAt($months, mktime(12, 0, 0, (int) $m[2], 15, (int) $m[1]));
            if (!isset($seasons[$s['key']])) {
                $seasons[$s['key']] = ['key' => $s['key'], 'name' => $s['name'], 'sort' => $s['startYear'] * 100 + $s['startMonth']];
            }
        }
    } catch (PDOException $e) {
        error_log('kbfSeasonsWithData: ' . $e->getMessage());
    }
    usort($seasons, function ($a, $b) { return $b['sort'] <=> $a['sort']; });
    return array_map(function ($s) { return ['key' => $s['key'], 'name' => $s['name']]; }, array_values($seasons));
}

// A YYYY-MM-DD date from the query string, or null
function kbfDateParam($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    list($y, $m, $d) = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y) ? $value : null;
}

// Offset for "Show more"
function kbfOffsetParam() {
    $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    return $offset === false ? 0 : (int) $offset;
}

// Escape % and _ for a LIKE search
function kbfLikeEscape($text) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $text);
}

// ============================================
// ACTIVITY LOG: friendly names
// Every action key written by auditLog(), and the scheduled jobs
// ("job:" + JobRuns.JobName). A key missing here is shown as itself.
// ============================================
const KBF_ACTION_NAMES = [
    // Signing in
    'login' => 'Signed in',
    'logout' => 'Signed out',
    'password_changed' => 'Changed their password',
    'signed_out_everywhere' => 'Signed out everywhere',
    'tournament_points_rechecked' => 'Rechecked points after a date change',
    'gallery_photos_uploaded' => 'Uploaded gallery photos',
    'gallery_photo_deleted' => 'Deleted a gallery photo',
    'password_change_forced' => 'Changed a temporary password',
    'password_reset' => 'Reset their password',
    'password_reset_sent' => 'Sent a password reset email',
    'temporary_password_set' => 'Set a temporary password',
    'two_step_enabled' => 'Turned on 2-step verification',
    'two_step_disabled' => 'Turned off 2-step verification',
    'two_step_reset' => 'Reset 2-step verification',
    'two_step_lockout' => 'Locked out after wrong codes',
    'two_step_backup_code_used' => 'Used a backup code',
    'two_step_backup_codes_created' => 'Made new backup codes',
    'two_step_policy_changed' => 'Changed the 2-step policy',
    'two_step_requirement_changed' => 'Changed who must use 2-step',
    // Staff and access
    'staff_granted' => 'Gave staff access',
    'staff_created' => 'Added a staff member',
    'access_updated' => 'Changed staff access',
    'access_converted' => 'Access converted (setup)',
    'login_enabled' => 'Allowed sign-in',
    'login_disabled' => 'Stopped sign-in',
    'superadmin_granted' => 'Made a Super Admin',
    'superadmin_removed' => 'Removed a Super Admin',
    'impersonation_started' => 'Started viewing as someone',
    'impersonation_ended' => 'Stopped viewing as someone',
    'impersonation_refused' => 'Viewing as someone was refused',
    'staff_deactivated' => 'Deactivated a staff member',
    // Players and approvals
    'player_created' => 'Added a player',
    'player_deactivated' => 'Deactivated a player',
    'player_reactivated' => 'Reactivated a player',
    'staff_reactivated' => 'Reactivated a staff member',
    'player_details_edited' => 'Edited a person\'s details',
    'player_removed_at_request' => 'Removed a player at their request',
    'registration_approved' => 'Approved a registration',
    'registration_rejected' => 'Rejected a registration',
    'grade_changed' => 'Changed a grade',
    // Tournaments and results
    'tournament_created' => 'Created a tournament',
    'tournament_edited' => 'Edited a tournament',
    'tournament_deleted' => 'Deleted a tournament',
    'tournament_status_changed' => 'Changed a tournament status',
    'tournament_approved' => 'Approved a tournament',
    'tournament_rejected' => 'Rejected a tournament',
    'results_reminder_sent' => 'Reminded an organiser about results',
    'results_submitted' => 'Submitted results',
    'results_approved' => 'Approved results',
    'results_rejected' => 'Rejected results',
    'results_edited' => 'Edited results',
    'result_deleted' => 'Deleted a result',
    // Content
    'broadcast_sent' => 'Sent a broadcast',
    'broadcast_draft_saved' => 'Saved a broadcast draft',
    'broadcast_scheduled' => 'Scheduled a broadcast',
    'broadcast_paused' => 'Paused a broadcast',
    'broadcast_resumed' => 'Resumed a broadcast',
    'broadcast_cancelled' => 'Cancelled a broadcast',
    'broadcast_duplicated' => 'Copied a broadcast',
    'broadcast_test_sent' => 'Sent a test broadcast',
    'sponsor_created' => 'Added a sponsor',
    'sponsor_updated' => 'Edited a sponsor',
    'sponsor_deleted' => 'Removed a sponsor',
    'sponsor_active_changed' => 'Showed or hid a sponsor',
    'sponsors_reordered' => 'Reordered sponsors',
    // Control centre
    'setting_changed' => 'Changed a setting',
    'maintenance_mode_changed' => 'Switched maintenance mode',
    'email_log_exported' => 'Downloaded the email log',
    'email_resend' => 'Resent an email',
    'notification_switch_changed' => 'Switched a notification',
    'email_template_changed' => 'Changed email wording',
    'email_template_restored' => 'Restored email wording',
    'email_test_sent' => 'Sent a test email',
    'email_attachment_added' => 'Added an email attachment',
    'email_attachment_removed' => 'Removed an email attachment',
    'notification_recipients_changed' => 'Changed who receives a notification',
    'notification_delivery_changed' => 'Changed how a notification is sent',
    'grade_changed_settings' => 'Changed a grade\'s settings',
    'grades_reordered' => 'Reordered grades',
    'grade_added' => 'Added a grade',
    'grade_retired' => 'Retired a grade',
    'grade_restored' => 'Restored a grade',
    'grade_deleted' => 'Deleted a grade',
    'grade_players_moved' => 'Moved players to another grade',
    'placing_points_changed' => 'Changed points per placing',
    'report_downloaded' => 'Downloaded a report',
    // Scheduled jobs (System)
    'job:broadcast-sender' => 'Email sender ran',
    'job:ranking-calculator' => 'Nightly recalculation ran',
    'job:term-reset' => 'Season reset ran',
];

function kbfActionName($key) {
    return KBF_ACTION_NAMES[$key] ?? (string) $key;
}
?>
