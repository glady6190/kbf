<?php
/**
 * Email Sender (cron, every minute) - file name kept as broadcast-sender.php
 * --------------------------------------------------------------------------
 * Every run, within the daily email allowance and unless sending is paused:
 *   1. sends waiting important and normal emails from EmailLog
 *      (important first, oldest first);
 *   2. starts scheduled broadcasts whose time has come (their recipients are
 *      worked out then, with api/broadcast-helpers.php);
 *   3. then sends queued broadcast recipients, up to broadcast_batch_size,
 *      through the shared sender (api/mailer.php) with priority bulk, with
 *      each recipient's placeholders ({first_name}, {full_name}, ...) filled in.
 *      Paused and cancelled broadcasts are skipped; a broadcast paused or
 *      cancelled during a run stops at once.
 * Every run also publishes scheduled news posts whose time has come (logged
 * in the Activity log), whatever the email allowance.
 * Once a day it also deletes EmailLog rows older than email_log_retention_days
 * (waiting emails are never deleted).
 *
 * Broadcast recipients that hit the daily limit stay 'pending' and are picked
 * up on a later run; the limit never marks anyone failed. A broadcast is
 * completed only when every recipient is sent or truly failed (opted-out
 * members are skipped, as before).
 *
 * A real run writes a JobRuns row only when it did something (sent, failed,
 * started or finished a broadcast, cleaned up) or failed, plus at most one
 * "nothing to do" row per hour. Dry runs write nothing.
 *
 * One run at a time: a database lock (GET_LOCK) is held for the whole run
 * and released by the database if the process dies. Each run stops starting
 * new sends after 50 seconds. The daily allowance is split three ways and
 * bulk is spread through the day (api/mailer.php); broadcasts may use a
 * separate service (BULK_SMTP_* in .env). An email with repeated temporary
 * failures is skipped for a while so the others carry on.
 *
 * Usage (CLI only):
 *     php jobs/broadcast-sender.php            # send
 *     php jobs/broadcast-sender.php --dry-run  # show what WOULD send, write nothing
 */

// ============================================
// CLI-ONLY GUARD
// ============================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

$dryRun = in_array('--dry-run', $argv, true);

date_default_timezone_set('Europe/London'); // UK time, as api/config.php (loaded below)

require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/config-helpers.php';
require_once dirname(__DIR__) . '/api/auth/email-config.php';
require_once dirname(__DIR__) . '/api/broadcast-helpers.php';

// ============================================
// SINGLE-RUN LOCK (real runs only)
// A database lock held on this run's connection for the whole run. It can
// never expire while the run is still working, and the database releases it
// by itself if the process dies (the connection closes), so two runs can
// never send the same emails.
// ============================================
const KBF_SENDER_LOCK = 'kbf_email_sender_run';
const KBF_SENDER_TIME_BUDGET = 50; // seconds: stop starting new sends after this (cron runs every minute)
$runStarted = time();

if (!$dryRun) {
    $gotLock = false;
    try {
        $gotLock = (int) $pdo->query("SELECT GET_LOCK('" . KBF_SENDER_LOCK . "', 0)")->fetchColumn() === 1;
    } catch (PDOException $e) {
        error_log('broadcast-sender: GET_LOCK failed: ' . $e->getMessage());
    }
    if (!$gotLock) {
        echo "another broadcast-sender run is in progress, exiting\n";
        exit(0);
    }
    register_shutdown_function(function () use ($pdo) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('" . KBF_SENDER_LOCK . "')");
        } catch (\Throwable $e) {
            // the connection closing releases it anyway
        }
    });
}

// Time left in this run's budget
function kbfSenderTimeUp() {
    global $runStarted;
    return time() - $runStarted >= KBF_SENDER_TIME_BUDGET;
}

// JobRuns: a row only when the run did something (or failed), plus at most
// one "nothing to do" row per hour, so the Activity log is not flooded
function kbfSenderRecordRun($pdo, $startedAt, $status, $summary, $didSomething) {
    try {
        if (!$didSomething && $status !== 'error') {
            $recent = (int) $pdo->query("
                SELECT COUNT(*) FROM JobRuns
                WHERE JobName = 'broadcast-sender' AND StartedDate > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ")->fetchColumn();
            if ($recent > 0) {
                return;
            }
        }
        $stmt = $pdo->prepare("
            INSERT INTO JobRuns (JobName, StartedDate, FinishedDate, Status, Summary)
            VALUES ('broadcast-sender', ?, NOW(), ?, ?)
        ");
        $stmt->execute([date('Y-m-d H:i:s', $startedAt), $status === 'error' ? 'error' : 'ok', substr((string) $summary, 0, 500)]);
    } catch (PDOException $e) {
        error_log('broadcast-sender: JobRuns write failed: ' . $e->getMessage());
    }
}

echo "Starting email sender" . ($dryRun ? " [DRY RUN]" : "") . "...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Summary counters
$sentThisRun   = 0;
$failedThisRun = 0;
$notes         = [];

// Stop sending for this run after a 'waiting' result, unless the email only
// had a real failure (then the next email may still go out)
// An email skipped for a while after repeated temporary failures never stops
// the others.
function kbfJobShouldStop($status) {
    return $status === 'waiting' && !in_array(kbfMailLastOutcome(), ['permanent', 'skipped'], true);
}

try {
    // ============================================
    // 1. SETTINGS
    // ============================================
    $batchSize = (int) getSetting($pdo, 'broadcast_batch_size', 20);
    $baseUrl   = trim((string) getSetting($pdo, 'site_base_url', 'https://kbf-uk.org'));
    if (!kbfIsValidSiteBaseUrl($baseUrl)) { $baseUrl = 'https://kbf-uk.org'; }
    $retention = (int) getSetting($pdo, 'email_log_retention_days', 180);
    if ($batchSize < 1) { $batchSize = 20; }

    $mailSettings = kbfMailSettings($pdo);
    $sent24h = kbfMailSentLast24h($pdo);

    $bulkService = kbfMailBulkService();
    echo "Daily limit: {$mailSettings['limit']}  |  Reserve (important): {$mailSettings['reserve']}  |  Reserve (normal): {$mailSettings['reserveNormal']}  |  Sent in last 24h: {$sent24h}\n";
    echo "Broadcasts: " . ($bulkService !== null
        ? "separate service {$bulkService['host']}, {$bulkService['limit']} per 24 hours"
        : "Hostinger mailbox, bulk share {$mailSettings['bulkShare']} per 24 hours") . "\n";
    echo "Broadcast batch size: {$batchSize}  |  Base URL: {$baseUrl}\n";
    if ($mailSettings['pausedUntil'] !== null) {
        echo "Sending is PAUSED until {$mailSettings['pausedUntil']}\n";
    }
    echo "\n";

    // ============================================
    // 1b. SCHEDULED NEWS POSTS whose time has come are published (news
    // editor; database/news-editor.sql). Each is logged (Activity log and
    // this run's summary). Not affected by the email allowance or a pause.
    // ============================================
    try {
        $stmt = $pdo->query("
            SELECT AnnouncementId, Title, ScheduledFor FROM Announcements
            WHERE PostStatus = 'scheduled' AND ScheduledFor IS NOT NULL AND ScheduledFor <= NOW()
            ORDER BY ScheduledFor ASC, AnnouncementId ASC
            LIMIT 50
        ");
        $duePosts = $stmt->fetchAll();
    } catch (PDOException $e) {
        // PostStatus not added yet
        $duePosts = [];
    }
    if ($duePosts) {
        $publish = $pdo->prepare("
            UPDATE Announcements
            SET PostStatus = 'published', IsPublished = 1, PublishedDate = NOW(), ScheduledFor = NULL, UpdatedDate = NOW()
            WHERE AnnouncementId = ? AND PostStatus = 'scheduled'
        ");
        $publishedPosts = 0;
        foreach ($duePosts as $post) {
            if ($dryRun) {
                echo "[DRY RUN] Would publish scheduled post #{$post['AnnouncementId']} (due {$post['ScheduledFor']})\n";
                continue;
            }
            $publish->execute([(int) $post['AnnouncementId']]);
            if ($publish->rowCount() === 0) {
                continue;
            }
            $publishedPosts++;
            echo "Published scheduled post #{$post['AnnouncementId']} (due {$post['ScheduledFor']})\n";
            if (function_exists('auditLog')) {
                auditLog('post_published', 'post', (int) $post['AnnouncementId'],
                    'Scheduled post "' . mb_substr((string) $post['Title'], 0, 80) . '" published (due ' . $post['ScheduledFor'] . ')', 0);
            }
        }
        if ($publishedPosts > 0) {
            $notes[] = "published {$publishedPosts} scheduled news post" . ($publishedPosts === 1 ? '' : 's');
        }
        echo "\n";
    }

    // ============================================
    // 2. DAILY CLEAN-UP of old EmailLog rows (sent or failed only)
    // A stamp file records the last clean-up so it runs once a day.
    // ============================================
    $stampFile = sys_get_temp_dir() . '/kbf-emaillog-cleanup.stamp';
    $cleanupDue = !file_exists($stampFile) || (time() - (int) @filemtime($stampFile)) >= 86400;
    if ($cleanupDue && $retention >= 1) {
        if ($dryRun) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM EmailLog
                WHERE Status IN ('sent', 'failed') AND CreatedDate < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$retention]);
            echo "[DRY RUN] Would delete " . (int) $stmt->fetchColumn() . " email log row(s) older than {$retention} days\n\n";
        } else {
            $stmt = $pdo->prepare("
                DELETE FROM EmailLog
                WHERE Status IN ('sent', 'failed') AND CreatedDate < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$retention]);
            $deleted = $stmt->rowCount();
            @touch($stampFile);
            echo "Deleted {$deleted} email log row(s) older than {$retention} days\n\n";
            if ($deleted > 0) {
                $notes[] = "deleted {$deleted} old log rows";
            }
        }
    }

    // ============================================
    // 3. WAITING IMPORTANT AND NORMAL EMAILS (important first, oldest first)
    // ============================================
    // SELECT * so AttachmentsJson comes along when the column exists
    // Emails being skipped for a while after temporary failures are left out
    $stmt = $pdo->query("
        SELECT *
        FROM EmailLog
        WHERE Status = 'waiting' AND Priority IN ('important', 'normal')
        " . (kbfMailHasRetryColumn($pdo) ? "AND (NextAttemptDate IS NULL OR NextAttemptDate <= NOW())" : "") . "
        ORDER BY (Priority = 'important') DESC, Id ASC
        LIMIT 500
    ");
    $waitingRows = $stmt->fetchAll();
    echo "Waiting important/normal emails: " . count($waitingRows) . "\n";

    $stopSending = $mailSettings['pausedUntil'] !== null;

    foreach ($waitingRows as $row) {
        if ($stopSending || (!$dryRun && kbfSenderTimeUp())) {
            break;
        }
        if ($dryRun) {
            echo "WOULD send #{$row['Id']} ({$row['EventKey']}, {$row['Priority']}) to {$row['ToEmail']}\n";
            continue;
        }
        // Important rows come first and have the larger allowance, so once a
        // row has no allowance, none of the rows after it has any either
        if (kbfMailRemaining($pdo, $row['Priority']) < 1) {
            break;
        }

        $status = kbfMailSendWaitingRow($pdo, $row);
        if ($status === 'sent') {
            $sentThisRun++;
            echo "  sent  -> #{$row['Id']} {$row['ToEmail']}\n";
        } elseif ($status === 'failed') {
            $failedThisRun++;
            echo "  FAIL  -> #{$row['Id']} {$row['ToEmail']}: " . kbfMailLastError() . "\n";
        } else {
            echo "  wait  -> #{$row['Id']} {$row['ToEmail']}: " . kbfMailLastError() . "\n";
            if (kbfJobShouldStop($status)) {
                $stopSending = true;
            }
        }
        usleep(200000); // 0.2s between sends
    }
    echo "\n";

    // ============================================
    // 4a. SCHEDULED BROADCASTS whose time has come
    // Recipients are worked out now, so the list is current. Needs
    // database/grading-broadcasts.sql (ScheduledFor).
    // ============================================
    if (kbfBroadcastSchemaReady($pdo)) {
        $stmt = $pdo->query("
            SELECT BroadcastId
            FROM EmailBroadcasts
            WHERE Status = 'scheduled' AND ScheduledFor IS NOT NULL AND ScheduledFor <= NOW()
            ORDER BY ScheduledFor ASC, BroadcastId ASC
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dueId) {
            $dueId = (int) $dueId;
            if ($dryRun) {
                echo "WOULD start scheduled broadcast #{$dueId}\n";
                continue;
            }
            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare("SELECT * FROM EmailBroadcasts WHERE BroadcastId = ? AND Status = 'scheduled' FOR UPDATE");
                $lock->execute([$dueId]);
                $due = $lock->fetch();
                if (!$due) {
                    $pdo->rollBack();
                    continue;
                }
                $target = kbfBroadcastTargetOf($due);
                $queued = kbfBroadcastFillQueue($pdo, $dueId, $target['type'], $target['grades']);
                if ($queued > 0) {
                    $upd = $pdo->prepare("UPDATE EmailBroadcasts SET Status = 'sending', UpdatedDate = NOW() WHERE BroadcastId = ?");
                    $upd->execute([$dueId]);
                    $notes[] = "broadcast #{$dueId} started ({$queued} recipients)";
                    echo "Scheduled broadcast #{$dueId} started for {$queued} recipient(s)\n";
                } else {
                    $upd = $pdo->prepare("UPDATE EmailBroadcasts SET Status = 'completed', CompletedDate = NOW(), UpdatedDate = NOW() WHERE BroadcastId = ?");
                    $upd->execute([$dueId]);
                    $notes[] = "broadcast #{$dueId} had no recipients";
                    echo "Scheduled broadcast #{$dueId} had no recipients - marked completed\n";
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("broadcast-sender: could not start scheduled broadcast #{$dueId}: " . $e->getMessage());
                echo "ERROR starting scheduled broadcast #{$dueId}: " . $e->getMessage() . "\n";
            }
        }
    }

    // ============================================
    // 4. BROADCASTS (one at a time, oldest first; paused, cancelled, draft
    // and scheduled ones are not picked up)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT *
        FROM EmailBroadcasts
        WHERE Status IN ('queued', 'sending')
        ORDER BY BroadcastId ASC
        LIMIT 1
    ");
    $stmt->execute();
    $broadcast = $stmt->fetch();

    if (!$broadcast) {
        echo "No broadcasts to process.\n";
    } else {
        $broadcastId = (int) $broadcast['BroadcastId'];
        $subject     = $broadcast['Subject'];
        $bodyHtml    = $broadcast['BodyHtml'];
        // "Attach to every email" files (database/notifications.sql column)
        $broadcastAttachments = [];
        if (!empty($broadcast['AttachmentsJson'])) {
            $decoded = json_decode((string) $broadcast['AttachmentsJson'], true);
            if (is_array($decoded)) {
                $broadcastAttachments = $decoded;
            }
        }

        echo "Processing broadcast #{$broadcastId}: \"{$subject}\" (status: {$broadcast['Status']})\n";

        if ($broadcast['Status'] === 'queued' && !$dryRun) {
            $stmt = $pdo->prepare("
                UPDATE EmailBroadcasts SET Status = 'sending'
                WHERE BroadcastId = ? AND Status = 'queued'
            ");
            $stmt->execute([$broadcastId]);
        }

        // How many broadcast emails may go out now
        // (With the separate broadcast service, a stop on the Hostinger side
        // does not hold broadcasts back.)
        $allowance = ($stopSending && $bulkService === null) ? 0 : ($dryRun ? $batchSize : kbfMailRemaining($pdo, 'bulk'));
        $take = max(0, min($batchSize, $allowance));
        echo "Broadcast emails allowed this run: {$take}\n\n";

        if ($take > 0) {
            // Pending recipients (opted-out members are skipped, as before)
            $stmt = $pdo->prepare("
                SELECT eq.QueueId, eq.UserId, eq.Attempts,
                       u.Email, u.FirstName, u.LastName, u.PlayerId, u.UnsubscribeToken,
                       c.ShortName AS GradeShort
                FROM EmailQueue eq
                JOIN Users u ON eq.UserId = u.UserId
                LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
                WHERE eq.BroadcastId = ?
                  AND eq.Status = 'pending'
                  AND u.EmailOptOut = 0
                  " . (kbfMailHasRetryColumn($pdo) ? "
                  AND NOT EXISTS (
                      SELECT 1 FROM EmailLog el
                      WHERE el.BroadcastId = eq.BroadcastId AND el.RelatedType = 'broadcast_recipient'
                        AND el.RelatedId = eq.QueueId AND el.Status = 'waiting'
                        AND el.NextAttemptDate > NOW())" : "") . "
                ORDER BY eq.QueueId ASC
                LIMIT {$take}
            ");
            $stmt->execute([$broadcastId]);
            $rows = $stmt->fetchAll();

            $tokenStmt     = $pdo->prepare("UPDATE Users SET UnsubscribeToken = ? WHERE UserId = ?");
            $attemptStmt   = $pdo->prepare("UPDATE EmailQueue SET Attempts = Attempts + 1 WHERE QueueId = ?");
            $sentStmt      = $pdo->prepare("UPDATE EmailQueue SET Status = 'sent', SentDate = NOW() WHERE QueueId = ?");
            $failStmt      = $pdo->prepare("UPDATE EmailQueue SET Status = 'failed', LastError = ? WHERE QueueId = ?");
            $sentCountStmt = $pdo->prepare("UPDATE EmailBroadcasts SET SentCount = SentCount + 1 WHERE BroadcastId = ?");
            $failCountStmt = $pdo->prepare("UPDATE EmailBroadcasts SET FailedCount = FailedCount + 1 WHERE BroadcastId = ?");
            // A recipient that is already waiting in the log is retried, not sent twice
            $waitingLogStmt = $pdo->prepare("
                SELECT *
                FROM EmailLog
                WHERE BroadcastId = ? AND RelatedType = 'broadcast_recipient' AND RelatedId = ? AND Status = 'waiting'
                ORDER BY Id DESC
                LIMIT 1
            ");

            // Paused or cancelled from the Broadcasts page during this run
            $statusStmt = $pdo->prepare("SELECT Status FROM EmailBroadcasts WHERE BroadcastId = ?");

            foreach ($rows as $row) {
                $queueId = (int) $row['QueueId'];
                $userId  = (int) $row['UserId'];
                $email   = $row['Email'];

                if ($dryRun) {
                    echo "WOULD send broadcast to {$email}\n";
                    continue;
                }

                if (kbfSenderTimeUp()) {
                    echo "  Time budget for this run used - the rest go out on the next run\n";
                    break;
                }
                $statusStmt->execute([$broadcastId]);
                if (!in_array((string) $statusStmt->fetchColumn(), ['queued', 'sending'], true)) {
                    echo "  Broadcast #{$broadcastId} was paused or cancelled - stopping\n";
                    break;
                }

                try {
                    $waitingLogStmt->execute([$broadcastId, $queueId]);
                    $existing = $waitingLogStmt->fetch();

                    if ($existing) {
                        $status = kbfMailSendWaitingRow($pdo, $existing);
                    } else {
                        $token = $row['UnsubscribeToken'];
                        if (empty($token)) {
                            $token = bin2hex(random_bytes(32));
                            $tokenStmt->execute([$token, $userId]);
                        }
                        $unsubscribeUrl = $baseUrl . '/unsubscribe.php?token=' . urlencode($token);
                        // This recipient's placeholders ({first_name}, {full_name}, ...)
                        $rendered = kbfBroadcastRender($pdo, $subject, $bodyHtml, $row);
                        $status = sendBroadcastEmail($email, $row['FirstName'], $rendered['subject'], $rendered['html'], $unsubscribeUrl, $broadcastId, $queueId, $broadcastAttachments);
                    }

                    if (in_array(kbfMailLastOutcome(), ['sent', 'ratelimit', 'temporary', 'permanent'], true)) {
                        $attemptStmt->execute([$queueId]);
                    }

                    if ($status === 'sent') {
                        $sentStmt->execute([$queueId]);
                        $sentCountStmt->execute([$broadcastId]);
                        $sentThisRun++;
                    } elseif ($status === 'failed') {
                        $failStmt->execute([kbfMailCut(kbfMailLastError(), 500), $queueId]);
                        $failCountStmt->execute([$broadcastId]);
                        $failedThisRun++;
                        echo "  FAIL  -> {$email}: " . kbfMailLastError() . "\n";
                    } else {
                        // Still pending: picked up again on a later run
                        echo "  wait  -> {$email}: " . kbfMailLastError() . "\n";
                        if (kbfJobShouldStop($status)) {
                            break;
                        }
                    }

                    usleep(200000); // 0.2s between sends
                } catch (Exception $e) {
                    // One recipient's problem must not stop the rest of the batch
                    error_log("Broadcast sender: recipient QueueId {$queueId} threw: " . $e->getMessage());
                    echo "  ERROR -> QueueId {$queueId}: " . $e->getMessage() . "\n";
                }
            }
        }

        // Completion: no pending recipients left (opted-out members excluded)
        if (!$dryRun) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS remaining
                FROM EmailQueue eq
                JOIN Users u ON eq.UserId = u.UserId
                WHERE eq.BroadcastId = ?
                  AND eq.Status = 'pending'
                  AND u.EmailOptOut = 0
            ");
            $stmt->execute([$broadcastId]);
            $remaining = (int) $stmt->fetch()['remaining'];

            // A broadcast paused or cancelled meanwhile keeps that status
            $statusNow = $pdo->prepare("SELECT Status FROM EmailBroadcasts WHERE BroadcastId = ?");
            $statusNow->execute([$broadcastId]);
            $stillSending = in_array((string) $statusNow->fetchColumn(), ['queued', 'sending'], true);

            if (!$stillSending) {
                echo "\nBroadcast #{$broadcastId} is paused or cancelled - left as it is.\n";
            } elseif ($remaining === 0) {
                $stmt = $pdo->prepare("
                    UPDATE EmailBroadcasts SET Status = 'completed', CompletedDate = NOW()
                    WHERE BroadcastId = ? AND Status IN ('queued', 'sending')
                ");
                $stmt->execute([$broadcastId]);
                echo "\nBroadcast #{$broadcastId} marked COMPLETED.\n";
                $notes[] = "broadcast #{$broadcastId} completed";

                // broadcast_finished for admins, with the counts
                $stmt = $pdo->prepare("SELECT Subject, TotalRecipients, SentCount, FailedCount FROM EmailBroadcasts WHERE BroadcastId = ?");
                $stmt->execute([$broadcastId]);
                $done = $stmt->fetch();
                if ($done) {
                    notify('broadcast_finished', [
                        'values' => [
                            'broadcast_subject' => html_entity_decode((string) $done['Subject'], ENT_QUOTES, 'UTF-8'),
                            'count' => (string) (int) $done['TotalRecipients'],
                            'sent_count' => (string) (int) $done['SentCount'],
                            'failed_count' => (string) (int) $done['FailedCount'],
                        ],
                        'relatedType' => 'broadcast',
                        'relatedId' => $broadcastId,
                    ]);
                }
            } else {
                echo "\nBroadcast #{$broadcastId} left 'sending' - {$remaining} recipient(s) remain for later runs.\n";
            }
        }
    }

    if ($dryRun) {
        echo "\n=== DRY RUN - no changes written ===\n";
        echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
        exit(0);
    }

    // ============================================
    // 5. SUMMARY ("Sent 18 emails, 4 waiting")
    // ============================================
    $waitingLog = (int) $pdo->query("
        SELECT COUNT(*) FROM EmailLog WHERE Status = 'waiting' AND Priority IN ('important', 'normal')
    ")->fetchColumn();
    $waitingBroadcast = (int) $pdo->query("
        SELECT COUNT(*)
        FROM EmailQueue eq
        JOIN EmailBroadcasts b ON b.BroadcastId = eq.BroadcastId
        JOIN Users u ON eq.UserId = u.UserId
        WHERE eq.Status = 'pending' AND b.Status IN ('queued', 'sending') AND u.EmailOptOut = 0
    ")->fetchColumn();
    $waitingTotal = $waitingLog + $waitingBroadcast;

    $summary = 'Sent ' . $sentThisRun . ' email' . ($sentThisRun === 1 ? '' : 's') . ', ' . $waitingTotal . ' waiting';
    if ($failedThisRun > 0) {
        $summary .= ', ' . $failedThisRun . ' failed';
    }
    $pausedNow = kbfMailSettings($pdo)['pausedUntil'];
    if ($pausedNow !== null) {
        $summary .= ', paused until ' . $pausedNow;
    }
    if (!empty($notes)) {
        $summary .= ' (' . implode('; ', $notes) . ')';
    }

    kbfSenderRecordRun($pdo, $runStarted, 'ok', $summary, $sentThisRun > 0 || $failedThisRun > 0 || !empty($notes));

    echo "\n=== Run Complete ===\n";
    echo $summary . "\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

} catch (PDOException $e) {
    error_log('Broadcast sender error: ' . $e->getMessage());
    if (!$dryRun) {
        kbfSenderRecordRun($pdo, $runStarted, 'error', 'Error after sending ' . $sentThisRun . ' email(s): ' . $e->getMessage(), true);
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
