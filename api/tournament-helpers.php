<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
// ============================================
// KBF-UK: TOURNAMENT STATUSES AND RESULTS REMINDERS
// Used by api/tournaments.php (admin Tournaments page) and the nightly
// jobs/ranking-calculator.php job.
//
// Statuses worked out for each tournament (kbfTournamentStatusSql):
//   upcoming      Approved, dated today or later
//   due           Approved, dated before today, no approved results
//   due_waiting   as due, but results have been submitted and are
//                 waiting for approval ("Results waiting approval")
//   waiting       Pending ("Waiting approval")
//   completed     Completed (or Approved, past, with approved results)
//   rejected      Rejected
//   cancelled     Cancelled (never due; shown only under All)
//
// The results_overdue email goes to the tournament's creator through
// notify(): by hand from the Tournaments page (at most once in 24 hours,
// even when the event is switched off) or once automatically by the
// nightly job when the event's organiser email is switched on.
// ============================================

require_once __DIR__ . '/config-helpers.php';

// SQL fragments on the Tournaments table aliased "t" (fixed strings)
const KBF_T_HAS_APPROVED_RESULTS = "EXISTS (SELECT 1 FROM Matches rm WHERE rm.TournamentId = t.TournamentId AND rm.IsApproved = 1)";
const KBF_T_HAS_WAITING_RESULTS = "EXISTS (SELECT 1 FROM ResultsApprovalQueue rq WHERE rq.TournamentId = t.TournamentId AND rq.Status = 'Pending')";

const KBF_TOURNAMENT_STATUS_LABELS = [
    'upcoming' => 'Upcoming',
    'due' => 'Results due',
    'due_waiting' => 'Results waiting approval',
    'waiting' => 'Waiting approval',
    'completed' => 'Completed',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
    'other' => 'Other',
];

// One status key per tournament, as an SQL expression. Its value is built
// from string literals, so it carries an explicit collation: comparing it
// with a parameter (e.g. AdminStatus = ?) can never mix collations,
// whatever the connection's own collation is.
function kbfTournamentStatusSql() {
    return "(CASE
        WHEN t.Status = 'Pending' THEN 'waiting'
        WHEN t.Status = 'Approved' AND DATE(t.TournamentDate) >= CURDATE() THEN 'upcoming'
        WHEN t.Status = 'Approved' AND NOT " . KBF_T_HAS_APPROVED_RESULTS . " AND " . KBF_T_HAS_WAITING_RESULTS . " THEN 'due_waiting'
        WHEN t.Status = 'Approved' AND NOT " . KBF_T_HAS_APPROVED_RESULTS . " THEN 'due'
        WHEN t.Status IN ('Approved', 'Completed') THEN 'completed'
        WHEN t.Status = 'Rejected' THEN 'rejected'
        WHEN t.Status = 'Cancelled' THEN 'cancelled'
        ELSE 'other'
    END) COLLATE utf8mb4_unicode_ci";
}

// The status key of one tournament, or null when it does not exist
function kbfTournamentStatusOf($pdo, $tournamentId) {
    $stmt = $pdo->prepare("SELECT " . kbfTournamentStatusSql() . " AS AdminStatus FROM Tournaments t WHERE t.TournamentId = ?");
    $stmt->execute([(int) $tournamentId]);
    $status = $stmt->fetchColumn();
    return $status === false ? null : (string) $status;
}

// The organiser's "submit results" page for a tournament
function kbfSubmitResultsLink($pdo, $tournamentId) {
    require_once __DIR__ . '/email-templates.php';
    return kbfSiteLink($pdo) . '/admin/submit-tournament-results?id=' . (int) $tournamentId;
}

// notify() context for results_overdue. $t: TournamentId, Name,
// TournamentDate, CreatedByAdminId.
function kbfResultsOverdueContext($pdo, array $t, $forced) {
    return [
        'organiser' => (int) $t['CreatedByAdminId'],
        'values' => [
            'tournament_name' => html_entity_decode((string) $t['Name'], ENT_QUOTES, 'UTF-8'),
            'tournament_date' => date('l, d F Y', strtotime((string) $t['TournamentDate'])),
            'submit_link' => kbfSubmitResultsLink($pdo, $t['TournamentId']),
        ],
        'relatedType' => 'tournament',
        'relatedId' => (int) $t['TournamentId'],
        'forceOrganiser' => (bool) $forced,
    ];
}

// ============================================
// AUTOMATIC REMINDERS (nightly job)
// For each tournament in Results due (not "Results waiting approval"),
// dated at least results_reminder_days ago, with no automatic reminder
// yet: send results_overdue once and set AutoResultsReminderDate.
// Returns ['on' => bool, 'days' => n, 'sent' => n, 'waiting' => n,
//          'failed' => n, 'none' => n]
// ============================================
function kbfSendAutoResultsReminders($pdo) {
    require_once __DIR__ . '/notify.php';
    $counts = ['on' => false, 'days' => 0, 'sent' => 0, 'waiting' => 0, 'failed' => 0, 'none' => 0];

    $settings = kbfNotifyEventSettings($pdo, 'results_overdue');
    if ($settings === null || !kbfNotifyChannelOn($settings, 'organiser')) {
        return $counts;
    }
    $counts['on'] = true;
    $days = kbfSettingInt('results_reminder_days', 7, 1, 60);
    $counts['days'] = $days;

    $stmt = $pdo->prepare("
        SELECT t.TournamentId, t.Name, t.TournamentDate, t.CreatedByAdminId
        FROM Tournaments t
        WHERE t.Status = 'Approved'
          AND t.AutoResultsReminderDate IS NULL
          AND DATE(t.TournamentDate) < CURDATE()
          AND DATE(t.TournamentDate) <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
          AND NOT " . KBF_T_HAS_APPROVED_RESULTS . "
          AND NOT " . KBF_T_HAS_WAITING_RESULTS . "
        ORDER BY t.TournamentDate ASC, t.TournamentId ASC
    ");
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll();

    // Claim each tournament first, so it can never get a second automatic reminder
    $claim = $pdo->prepare("
        UPDATE Tournaments SET AutoResultsReminderDate = NOW()
        WHERE TournamentId = ? AND AutoResultsReminderDate IS NULL
    ");

    foreach ($rows as $t) {
        $claim->execute([(int) $t['TournamentId']]);
        if ($claim->rowCount() === 0) {
            continue;
        }
        $results = notify('results_overdue', kbfResultsOverdueContext($pdo, $t, false));
        $status = (string) ($results['organiser'] ?? 'failed');
        if (!isset($counts[$status]) || $status === 'on' || $status === 'days') {
            $status = $status === 'off' ? 'none' : 'failed';
        }
        $counts[$status]++;

        if (function_exists('auditLog')) {
            auditLog('results_reminder_sent', 'tournament', (int) $t['TournamentId'],
                'Automatic reminder to the organiser (' . $status . ')', 0);
        }
    }
    return $counts;
}

// ============================================
// SIMILAR TOURNAMENTS (duplicate check before creating one)
// Used by api/tournaments.php (?action=similar, the Create tournament form)
// and api/admin-quick-tournament.php (Add results for a past tournament).
// Similar: dated within 3 days of the date, and names that match ignoring
// case, spaces and punctuation, or where one contains the other.
// ============================================

// A name with case, spaces and punctuation removed
function kbfSimilarNameKey($name) {
    $text = html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8');
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
}

function kbfNamesSimilar($a, $b) {
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    // Containment only for names long enough to mean something
    $shorter = strlen($a) <= strlen($b) ? $a : $b;
    $longer = $shorter === $a ? $b : $a;
    return strlen($shorter) >= 4 && strpos($longer, $shorter) !== false;
}

// Tournaments similar to $name on $date (YYYY-MM-DD), at most 10.
// $seeAll: false shows Pending and Rejected ones only when $userId created them.
function kbfSimilarTournaments($pdo, $name, $date, $userId = 0, $seeAll = true) {
    $key = kbfSimilarNameKey($name);
    if ($key === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT t.TournamentId, t.Name, t.TournamentDate, t.Status, t.CreatedByAdminId,
               t.Venue, t.Location,
               " . organiserNameSql('t', 'c') . " AS OrganiserName,
               " . kbfTournamentStatusSql() . " AS AdminStatus,
               (SELECT COUNT(*) FROM Matches m WHERE m.TournamentId = t.TournamentId) AS MatchCount,
               (SELECT COUNT(DISTINCT TRIM(COALESCE(m.GroupName, ''))) FROM Matches m WHERE m.TournamentId = t.TournamentId) AS CategoryCount
        FROM Tournaments t
        LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
        WHERE DATE(t.TournamentDate) BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)
        ORDER BY ABS(DATEDIFF(DATE(t.TournamentDate), ?)) ASC, t.TournamentId DESC
        LIMIT 300
    ");
    $stmt->execute([$date, $date, $date]);

    $today = date('Y-m-d');
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        if (!kbfNamesSimilar($key, kbfSimilarNameKey($r['Name']))) {
            continue;
        }
        $isOwn = $userId > 0 && (int) $r['CreatedByAdminId'] === (int) $userId;
        if (!$seeAll && in_array($r['Status'], ['Pending', 'Rejected'], true) && !$isOwn) {
            continue;
        }
        $status = (string) $r['AdminStatus'];
        $tDate = substr((string) $r['TournamentDate'], 0, 10);
        $out[] = [
            'id' => (int) $r['TournamentId'],
            'name' => (string) $r['Name'],
            'date' => $tDate,
            'organiser' => (string) $r['OrganiserName'],
            'venue' => (string) $r['Venue'],
            'town' => (string) $r['Location'],
            'status' => $status,
            'statusLabel' => KBF_TOURNAMENT_STATUS_LABELS[$status] ?? $status,
            'matchCount' => (int) $r['MatchCount'],
            'categoryCount' => (int) $r['CategoryCount'],
            // Results can be entered (api/matches.php resultsEntryBlock)
            'canEnterResults' => in_array($r['Status'], ['Approved', 'Completed'], true) && $tDate !== '' && $tDate <= $today,
        ];
        if (count($out) >= 10) {
            break;
        }
    }
    return $out;
}
?>
