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
    // Get all active players
    $stmt = $pdo->query("
        SELECT UserId
        FROM Users
        WHERE IsActive = 1
          AND IsRemoved = 0
          AND CategoryId IS NOT NULL
    ");
    $players = $stmt->fetchAll();
    
    echo "Found " . count($players) . " active players\n\n";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($players as $player) {
        $playerId = $player['UserId'];
        
        if (calculatePlayerStats($pdo, $playerId)) {
            $successCount++;
        } else {
            $errorCount++;
        }
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