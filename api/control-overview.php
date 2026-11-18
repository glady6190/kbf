<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/mailer.php';

// ============================================
// CONTROL CENTRE: OVERVIEW
//
//   GET   figures, needs attention, email allowance and scheduled jobs
//
// Open to Super Admins and to anyone with at least one control.* permission.
// Each part is included only when the person can use the matching section:
//   members, upcoming          everyone who can open the page
//   waiting                    players.approve_registrations and/or tournaments.approve
//   emails, emailAllowance     control.email
//   attention items            only those the person can act on
//   jobs                       Super Admin
// Reading is allowed while impersonating. Times are ISO 8601 with the
// server's offset, so the page shows them in UK time.
// ============================================

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

$isSuper = isSuperAdmin();
if (!$isSuper && !hasAnyPermission(KBF_CONTROL_PERMISSION_KEYS)) {
    sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
}

$can = [
    'registrations' => hasPermission('players.approve_registrations'),
    'tournaments' => hasPermission('tournaments.approve'),
    'grades' => hasPermission('players.change_grades'),
    'email' => hasPermission('control.email'),
    'broadcasts' => hasPermission('content.broadcasts'),
    'grading' => hasPermission('control.grading'),
    'super' => $isSuper,
];

// Scheduled jobs: name => [label, description, overdue after (seconds)]
const OVERVIEW_JOBS = [
    'broadcast-sender' => ['Email sender', 'Sends waiting emails and broadcasts, every minute.', 1800],
    'ranking-calculator' => ['Nightly recalculation', 'Recalculates player stats, sends daily summaries and tidies the logs.', 26 * 3600],
    'term-reset' => ['Season reset', 'Checks daily and resets season points when a new season starts.', 26 * 3600],
];

function isoTime($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? null : date('c', $ts);
}

function oneValue($pdo, $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function plural($n, $one, $many = null) {
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

try {
    $response = ['success' => true, 'can' => $can];
    $attention = [];

    // ============================================
    // FIGURES
    // ============================================
    $response['members'] = [
        'players' => (int) oneValue($pdo, "SELECT COUNT(*) FROM Users WHERE IsActive = 1 AND CategoryId IS NOT NULL"),
        'staff' => (int) oneValue($pdo, "
            SELECT COUNT(*) FROM Users u
            WHERE u.IsActive = 1
              AND (u.IsSuperAdmin = 1 OR EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId))"),
    ];

    $next = oneValue($pdo, "SELECT MIN(TournamentDate) FROM Tournaments WHERE Status = 'Approved' AND TournamentDate >= CURDATE()");
    $response['upcoming'] = [
        'count' => (int) oneValue($pdo, "SELECT COUNT(*) FROM Tournaments WHERE Status = 'Approved' AND TournamentDate >= CURDATE()"),
        'nextDate' => $next ? date('Y-m-d', strtotime($next)) : null,
    ];

    if ($can['registrations'] || $can['tournaments']) {
        $waiting = ['registrations' => null, 'oldestDays' => null, 'tournaments' => null, 'results' => null];
        if ($can['registrations']) {
            $stmt = $pdo->query("
                SELECT COUNT(*) AS n, DATEDIFF(NOW(), MIN(SubmittedDate)) AS oldest
                FROM PlayerRegistrations WHERE Status = 'Pending'
            ");
            $r = $stmt->fetch();
            $waiting['registrations'] = (int) $r['n'];
            $waiting['oldestDays'] = $r['n'] > 0 ? (int) $r['oldest'] : null;

            // Waiting longer than overview_waiting_days (3 when not set)
            $days = kbfSettingInt('overview_waiting_days', 3, 1, 60);
            $old = (int) oneValue($pdo, "
                SELECT COUNT(*) FROM PlayerRegistrations
                WHERE Status = 'Pending' AND SubmittedDate < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
            if ($old > 0) {
                $attention[] = [
                    'title' => plural($old, 'registration') . ' waiting more than ' . plural($days, 'day'),
                    'text' => 'The oldest was sent ' . plural((int) $waiting['oldestDays'], 'day') . ' ago.',
                    'button' => 'Review registrations',
                    'link' => '/admin/approvals',
                ];
            }
        }
        if ($can['tournaments']) {
            $waiting['tournaments'] = (int) oneValue($pdo, "SELECT COUNT(*) FROM Tournaments WHERE Status = 'Pending'");
            $waiting['results'] = (int) oneValue($pdo, "SELECT COUNT(*) FROM ResultsApprovalQueue WHERE Status = 'Pending'");
            if ($waiting['tournaments'] + $waiting['results'] > 0) {
                $parts = [];
                if ($waiting['tournaments'] > 0) {
                    $parts[] = plural($waiting['tournaments'], 'tournament');
                }
                if ($waiting['results'] > 0) {
                    $parts[] = plural($waiting['results'], 'set', 'sets') . ' of results';
                }
                $attention[] = [
                    'title' => implode(' and ', $parts) . ' waiting for approval',
                    'text' => 'Organisers are waiting for a decision.',
                    'button' => 'Open approvals',
                    'link' => '/admin/approvals',
                ];
            }
        }
        $response['waiting'] = $waiting;
    }

    // ============================================
    // PROMOTIONS
    // ============================================
    if ($can['grades']) {
        $eligible = (int) oneValue($pdo, "
            SELECT COUNT(*) FROM PlayerPerformanceTracker pt
            JOIN Users u ON u.UserId = pt.PlayerId
            WHERE pt.EligibleForPromotion = 1 AND u.IsActive = 1");
        if ($eligible > 0) {
            $attention[] = [
                'title' => plural($eligible, 'player') . ' eligible for promotion',
                'text' => 'They are skipped by the next season reset until promoted.',
                'button' => 'Open grading',
                'link' => '/admin/rankings',
            ];
        }
    }

    // ============================================
    // EMAIL
    // ============================================
    if ($can['email']) {
        $mail = kbfMailSettings($pdo);
        $sent = kbfMailSentLast24h($pdo);
        $failed = 0;
        try {
            $failed = (int) oneValue($pdo, "SELECT COUNT(*) FROM EmailLog WHERE Status = 'failed' AND CreatedDate > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        } catch (PDOException $e) {
            $failed = 0;
        }
        $response['emails'] = [
            'sent' => $sent,
            'failed' => $failed,
            'limit' => $mail['limit'],
            'reserve' => $mail['reserve'],
            'broadcastCap' => max(0, $mail['limit'] - $mail['reserve']),
            'pausedUntil' => isoTime($mail['pausedUntil']),
        ];
        if ($failed > 0) {
            $attention[] = [
                'title' => plural($failed, 'email') . ' failed in the last 24 hours',
                'text' => 'See the reason in the email log, then resend where it makes sense.',
                'button' => 'Open the email log',
                'link' => '/admin/email-limits',
            ];
        }
        if ($mail['pausedUntil'] !== null) {
            $attention[] = [
                'title' => 'Email sending is paused',
                'text' => 'The mail server refused because of its own limit. Emails wait and go out automatically after the pause.',
                'button' => 'Email and limits',
                'link' => '/admin/email-limits',
            ];
        }
    }

    // Oldest broadcast still sending
    $broadcast = null;
    if ($can['email'] || $can['broadcasts']) {
        try {
            $stmt = $pdo->query("
                SELECT BroadcastId, Subject, SentCount, TotalRecipients, CreatedDate
                FROM EmailBroadcasts WHERE Status IN ('queued', 'sending')
                ORDER BY BroadcastId ASC LIMIT 1
            ");
            $b = $stmt->fetch();
            if ($b) {
                $broadcast = [
                    'subject' => html_entity_decode((string) $b['Subject'], ENT_QUOTES, 'UTF-8'),
                    'sent' => (int) $b['SentCount'],
                    'total' => (int) $b['TotalRecipients'],
                    'created' => isoTime($b['CreatedDate']),
                ];
                $attention[] = [
                    'title' => 'A broadcast is still sending',
                    'text' => '"' . $broadcast['subject'] . '": ' . $broadcast['sent'] . ' of ' . $broadcast['total'] . ' sent.',
                    'button' => $can['broadcasts'] ? 'Open broadcasts' : 'Email and limits',
                    'link' => $can['broadcasts'] ? '/admin/broadcasts' : '/admin/email-limits',
                ];
            }
        } catch (PDOException $e) {
            $broadcast = null;
        }
    }
    if ($can['email']) {
        $response['emailAllowance'] = $response['emails'] + ['broadcast' => $broadcast];
    }

    // ============================================
    // SEASON RESET (within 7 days)
    // ============================================
    $seasonPlan = null;
    if ($can['grading'] || $can['super']) {
        $seasonPlan = kbfSeasonPlan($pdo, kbfSeasonSettings($pdo), null, $can['grading']);
    }
    if ($can['grading'] && $seasonPlan) {
        $daysToReset = (int) floor((strtotime($seasonPlan['nextResetDate']) - strtotime(date('Y-m-d'))) / 86400);
        if ($seasonPlan['status'] !== 'baseline' && $daysToReset <= 7) {
            $changes = $seasonPlan['counts']['floor'] + $seasonPlan['counts']['zero'];
            $attention[] = [
                'title' => $daysToReset <= 0 ? 'The season reset is due now' : 'The next season reset is in ' . plural($daysToReset, 'day'),
                'text' => 'On ' . $seasonPlan['nextResetLabel'] . ' it would change ' . plural($changes, 'player') . '\'s season points'
                    . ($seasonPlan['counts']['skipped'] > 0 ? ' (' . $seasonPlan['counts']['skipped'] . ' eligible for promotion are skipped).' : '.'),
                'button' => 'Grading and seasons',
                'link' => '/admin/grading-settings',
            ];
        }
    }

    // ============================================
    // SUPER ADMIN: JOBS, 2-STEP, MAINTENANCE
    // ============================================
    if ($can['super']) {
        $jobs = [];
        $problems = [];
        $lastRuns = [];
        try {
            $stmt = $pdo->query("
                SELECT j.JobName, j.StartedDate, j.FinishedDate, j.Status, j.Summary
                FROM JobRuns j
                JOIN (SELECT JobName, MAX(Id) AS LastId FROM JobRuns GROUP BY JobName) last ON last.LastId = j.Id
            ");
            foreach ($stmt->fetchAll() as $r) {
                $lastRuns[$r['JobName']] = $r;
            }
        } catch (PDOException $e) {
            $lastRuns = [];
        }

        foreach (OVERVIEW_JOBS as $name => $info) {
            $run = $lastRuns[$name] ?? null;
            $startedTs = $run ? strtotime($run['StartedDate']) : false;
            $overdue = !$run || $startedTs === false || (time() - $startedTs) > $info[2];
            $error = $run && $run['Status'] === 'error';
            if ($name === 'broadcast-sender') {
                $nextRun = $startedTs ? date('c', $startedTs + 60) : null;
            } else {
                $nextRun = $startedTs ? date('c', $startedTs + 86400) : null;
            }
            $jobs[] = [
                'name' => $name,
                'label' => $info[0],
                'description' => $info[1],
                'lastRun' => $run ? isoTime($run['StartedDate']) : null,
                'status' => $error ? 'error' : ($overdue ? 'overdue' : 'ok'),
                'summary' => $run ? (string) $run['Summary'] : '',
                'nextRun' => $nextRun,
                'nextReset' => $name === 'term-reset' && $seasonPlan ? $seasonPlan['nextResetDate'] : null,
            ];
            if ($error) {
                $problems[] = $info[0] . ' ended in an error';
            } elseif ($overdue) {
                $problems[] = $info[0] . ($run ? ' has not run when expected' : ' has never run');
            }
        }
        $response['jobs'] = $jobs;

        if ($problems) {
            $attention[] = [
                'title' => count($problems) === 1 ? $problems[0] : 'Scheduled jobs need checking',
                'text' => (count($problems) > 1 ? implode('; ', $problems) . '. ' : '') . 'Check the cron jobs in hosting.',
                'button' => 'Open the activity log',
                'link' => '/admin/activity-log',
            ];
        }

        // Staff who must use 2-step but have not set it up
        try {
            $policy = twoFactorPolicy();
            $stmt = $pdo->query("
                SELECT u.UserId, u.IsSuperAdmin, u.TwoFactorRequired,
                       (SELECT GROUP_CONCAT(sr.RoleKey) FROM StaffRoles sr WHERE sr.UserId = u.UserId) AS Roles
                FROM Users u
                WHERE u.IsActive = 1 AND u.TwoFactorEnabledDate IS NULL
                  AND (u.IsSuperAdmin = 1 OR EXISTS (SELECT 1 FROM UserPermissions sp WHERE sp.UserId = u.UserId))
            ");
            $missing = 0;
            foreach ($stmt->fetchAll() as $r) {
                $labels = array_filter(explode(',', (string) $r['Roles']));
                if (twoFactorMustUseFor($policy, (int) $r['IsSuperAdmin'] === 1, $labels, (int) $r['TwoFactorRequired'] === 1)) {
                    $missing++;
                }
            }
            if ($missing > 0) {
                $attention[] = [
                    'title' => plural($missing, 'staff member') . ' must set up 2-step verification',
                    'text' => 'They are asked to set it up at their next sign-in.',
                    'button' => 'Open staff',
                    'link' => '/admin/staff',
                ];
            }
        } catch (PDOException $e) {
            // two-step.sql not run: nothing to check
        }

        if (kbfSettingInt('maintenance_mode', 0, 0, 1) === 1) {
            $attention[] = [
                'title' => 'Maintenance mode is on',
                'text' => 'Visitors see the back-soon page. Staff can still use the whole site.',
                'button' => 'Site settings',
                'link' => '/admin/site-settings',
            ];
        }
    }

    $response['attention'] = $attention;
    $response['serverTime'] = date('c');
    sendJSON($response);

} catch (PDOException $e) {
    error_log('control-overview.php: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'The overview could not be loaded.'], 500);
}
?>
