<?php
//header('Content-Type: text/plain');

// ============================================
// CLI-ONLY GUARD
// (recalculates stats for every player in bulk; must never be web-triggerable)
// ============================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/config-helpers.php';
require_once dirname(__DIR__) . '/api/player-stats-calculator.php';

echo "Starting ranking calculation...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Record this run in JobRuns (this job has no dry run)
$runId = kbfJobRunStart($pdo, 'ranking-calculator');

try {
    // Every active player with a grade, with a few set-based queries for
    // all of them at once (api/player-stats-calculator.php)
    $successCount = 0;
    $errorCount = 0;
    $saved = kbfRecalculatePlayerStats($pdo, null);
    if ($saved === false) {
        $errorCount = 1;
        echo "ERROR: the player stats could not be calculated (see the error log)\n";
    } else {
        $successCount = (int) $saved;
    }
    
    echo "\n=== Calculation Complete ===\n";
    echo "Successfully updated: $successCount players\n";
    echo "Errors: $errorCount players\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

    // Nightly summaries: admin emails set to "One summary email a day"
    $digestNote = '';
    try {
        require_once dirname(__DIR__) . '/api/notify.php';
        $digests = kbfNotifySendDigests($pdo);
        $digestTotal = $digests['sent'] + $digests['waiting'] + $digests['failed'];
        if ($digestTotal > 0) {
            $digestNote = '; ' . $digestTotal . ' daily summar' . ($digestTotal === 1 ? 'y' : 'ies')
                . ' (' . $digests['sent'] . ' sent, ' . $digests['waiting'] . ' waiting, ' . $digests['failed'] . ' failed)';
        }
        echo "Daily summaries: {$digests['sent']} sent, {$digests['waiting']} waiting, {$digests['failed']} failed\n";
    } catch (\Throwable $e) {
        error_log('ranking-calculator: daily summaries error: ' . $e->getMessage());
        $digestNote = '; daily summaries could not be sent';
    }

    // Results reminders: one automatic email per tournament whose results
    // are overdue, when "Results not yet submitted" is switched on
    $reminderNote = '';
    try {
        require_once dirname(__DIR__) . '/api/tournament-helpers.php';
        $reminders = kbfSendAutoResultsReminders($pdo);
        if ($reminders['on']) {
            $reminderTotal = $reminders['sent'] + $reminders['waiting'] + $reminders['failed'] + $reminders['none'];
            if ($reminderTotal > 0) {
                $reminderNote = '; ' . $reminderTotal . ' results reminder' . ($reminderTotal === 1 ? '' : 's')
                    . ' (' . $reminders['sent'] . ' sent, ' . $reminders['waiting'] . ' waiting, '
                    . $reminders['failed'] . ' failed, ' . $reminders['none'] . ' without an email address)';
            }
            echo "Results reminders: {$reminders['sent']} sent, {$reminders['waiting']} waiting, {$reminders['failed']} failed, {$reminders['none']} without an email address\n";
        } else {
            echo "Results reminders: switched off\n";
        }
    } catch (\Throwable $e) {
        error_log('ranking-calculator: results reminders error: ' . $e->getMessage());
        $reminderNote = '; results reminders could not be sent';
    }

    // Activity log clean-up: AuditLog older than audit_log_retention_days
    // (Security page, 730 days by default) and JobRuns older than 90 days
    $cleanupNote = '';
    try {
        $keepDays = kbfSettingInt('audit_log_retention_days', 730, 90, 3650);
        $stmt = $pdo->prepare("DELETE FROM AuditLog WHERE CreatedDate < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$keepDays]);
        $auditDeleted = $stmt->rowCount();
        $stmt = $pdo->prepare("DELETE FROM JobRuns WHERE StartedDate < DATE_SUB(NOW(), INTERVAL 90 DAY) AND Id <> ?");
        $stmt->execute([(int) $runId]);
        $jobsDeleted = $stmt->rowCount();
        echo "Activity log clean-up: {$auditDeleted} log entries older than {$keepDays} days, {$jobsDeleted} job runs older than 90 days\n";
        if ($auditDeleted + $jobsDeleted > 0) {
            $cleanupNote = '; removed ' . $auditDeleted . ' old log entries and ' . $jobsDeleted . ' old job runs';
        }
    } catch (PDOException $e) {
        error_log('ranking-calculator: activity log clean-up error: ' . $e->getMessage());
    }

    // Trimming: tables that otherwise grow for ever, keeping only what is
    // needed. Each step on its own, so one problem never stops the rest.
    $trimSteps = [
        // Recipients of broadcasts finished or cancelled more than 90 days ago
        'broadcast recipients' => ["
            DELETE eq FROM EmailQueue eq
            JOIN EmailBroadcasts b ON b.BroadcastId = eq.BroadcastId
            WHERE b.Status IN ('completed', 'cancelled')
              AND COALESCE(b.CompletedDate, b.CancelledDate, b.CreatedDate) < DATE_SUB(NOW(), INTERVAL 90 DAY)
        ", []],
        // Sign-in attempts: successful ones after 30 days, failed ones after a day
        'sign-in attempts' => ["
            DELETE FROM LoginAttempts
            WHERE (Success = 1 AND AttemptTime < DATE_SUB(NOW(), INTERVAL 30 DAY))
               OR (Success = 0 AND AttemptTime < DATE_SUB(NOW(), INTERVAL 1 DAY))
        ", []],
        // Request counts (password reset limits) after a day
        'request limits' => ["DELETE FROM RequestLimits WHERE CreatedAt < DATE_SUB(NOW(), INTERVAL 1 DAY)", []],
        // Password reset links used or expired more than 7 days ago
        'password reset links' => ["
            DELETE FROM PasswordResets
            WHERE (Used = 1 AND CreatedAt < DATE_SUB(NOW(), INTERVAL 7 DAY))
               OR ExpiresAt < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ", []],
        // Daily-summary items never sent (e.g. no recipient left) after 7 days
        'daily-summary items' => ["DELETE FROM NotificationDigest WHERE CreatedDate < DATE_SUB(NOW(), INTERVAL 7 DAY)", []],
        // Approval history and the old activity log: the activity log period
        'approval history' => ["DELETE FROM ApprovalLogs WHERE LogDate < DATE_SUB(NOW(), INTERVAL ? DAY)", [$keepDays ?? 730]],
        'old activity log' => ["DELETE FROM UserActivityLogs WHERE LogDate < DATE_SUB(NOW(), INTERVAL ? DAY)", [$keepDays ?? 730]],
    ];
    $trimmed = [];
    foreach ($trimSteps as $label => list($sql, $params)) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                $trimmed[] = $stmt->rowCount() . ' ' . $label;
            }
        } catch (PDOException $e) {
            error_log('ranking-calculator: trimming ' . $label . ' failed: ' . $e->getMessage());
        }
    }
    // Sent broadcast emails keep their log row, but not each personalised
    // body, after 30 days (the broadcast itself keeps the wording)
    try {
        $stmt = $pdo->prepare("
            UPDATE EmailLog SET BodyHtml = NULL, BodyText = NULL
            WHERE Priority = 'bulk' AND Status = 'sent' AND SentDate < DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND BodyHtml IS NOT NULL
        ");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $trimmed[] = $stmt->rowCount() . ' broadcast email bodies';
        }
    } catch (PDOException $e) {
        error_log('ranking-calculator: trimming broadcast email bodies failed: ' . $e->getMessage());
    }
    if ($trimmed) {
        echo "Trimmed: " . implode(', ', $trimmed) . "\n";
        $cleanupNote .= '; trimmed ' . implode(', ', $trimmed);
    }

    kbfJobRunFinish($pdo, $runId, $errorCount > 0 ? 'error' : 'ok',
        'Recalculated ' . $successCount . ' player' . ($successCount === 1 ? '' : 's')
        . ($errorCount > 0 ? ', ' . $errorCount . ' error' . ($errorCount === 1 ? '' : 's') : '')
        . $digestNote . $reminderNote . $cleanupNote);

} catch (PDOException $e) {
    kbfJobRunFinish($pdo, $runId, 'error', 'Error: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>