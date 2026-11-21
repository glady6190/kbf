<?php
/**
 * Season (Term) Points Reset
 * --------------------------------------------------------------------------
 * Resets every active player's season points (Users.TotalPoints) at the start
 * of each season. Seasons start in the months in Settings.term_start_months
 * (e.g. "1,7": January and July).
 *
 * The rules live in api/grading.php (kbfSeasonPlan), shared with the preview
 * on the Grading and seasons page, so the job and the preview always agree:
 *   - players eligible for promotion are left unchanged
 *   - players on term_reset_floor_trigger points or more restart on
 *     term_reset_floor_value
 *   - everyone else restarts on 0
 *
 * This script is date-driven and idempotent: cron runs it (e.g. daily) and it
 * compares the current season key with Settings.last_term_reset. Running it
 * again within the same season does nothing after the first reset. The very
 * first run (no last_term_reset yet) only records the current season.
 *
 * The reset runs in one transaction (kbfRunSeasonReset): each player's
 * points are locked and read again, awards from tournaments dated in the new
 * season that were already added stay in season points (still counted), the
 * player's other counted awards are marked as no longer counted, and
 * last_term_reset is saved in the same transaction.
 *
 * Usage (CLI only):
 *     php jobs/term-reset.php            # perform the reset
 *     php jobs/term-reset.php --dry-run  # show what WOULD change, write nothing
 *
 * Users.TotalPoints and PlayerPointsHistory.CountsTowardSeason are modified.
 * Award rows, career points and CategoryId are never touched.
 */

// ============================================
// CLI-ONLY GUARD
// (mutates player points in bulk; must never be web-triggerable)
// ============================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/config-helpers.php';
require_once dirname(__DIR__) . '/api/grading.php';

// --dry-run: computes and prints everything but performs no UPDATEs and no
// Settings write. Detected from $argv so a dry run can never reach a write path.
$dryRun = in_array('--dry-run', $argv, true);

echo "Starting term points reset" . ($dryRun ? " [DRY RUN]" : "") . "...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Record this run in JobRuns (dry runs write nothing)
$runId = $dryRun ? 0 : kbfJobRunStart($pdo, 'term-reset');
// Last check-in time for the Overview (one Settings value, no new rows)
if (!$dryRun) {
    kbfJobCheckIn($pdo, 'term-reset');
}

try {
    // ============================================
    // 1. SETTINGS AND PLAN (shared rules)
    // ============================================
    $settings = kbfSeasonSettings($pdo);
    // Status first; the player list is only built when a reset is due
    $plan = kbfSeasonPlan($pdo, $settings, null, false);
    if ($plan['status'] === 'due') {
        $plan = kbfSeasonPlan($pdo, $settings, null, true);
    }
    $currentTermKey = $plan['season']['key'];
    $floorTrigger = (int) $settings['trigger'];
    $floorValue = (int) $settings['value'];

    echo "Current term key: {$currentTermKey}\n";
    echo "Last term reset:  " . ($settings['lastTermReset'] === '' ? '(none)' : $settings['lastTermReset']) . "\n";
    echo "Floor trigger: {$floorTrigger}  ->  Floor value: {$floorValue}\n\n";

    // ============================================
    // 2. FIRST-EVER RUN: establish baseline, reset nobody
    // ============================================
    if ($plan['status'] === 'baseline') {
        if ($dryRun) {
            echo "[DRY RUN] Would establish baseline: {$currentTermKey} (no players reset)\n";
            echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
            exit(0);
        }
        // Saved inside the shared transaction (re-checked under a lock)
        $result = kbfRunSeasonReset($pdo);
        $currentTermKey = $result['key'];
        echo "Baseline established: {$currentTermKey}\n";
        echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
        kbfJobRunFinish($pdo, $runId, 'ok', 'Baseline set to ' . $currentTermKey . ', nobody reset');
        exit(0);
    }

    // ============================================
    // 3. ALREADY PROCESSED THIS TERM: idempotent no-op
    // ============================================
    if ($plan['status'] === 'done') {
        echo "Already reset for {$currentTermKey}, nothing to do.\n";
        echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
        kbfJobRunFinish($pdo, $runId, 'ok', 'Already reset for ' . $currentTermKey . ', nothing to do');
        exit(0);
    }

    // ============================================
    // 4. NEW TERM: perform the reset
    // ============================================
    $flooredCount = $plan['counts']['floor'];
    $zeroedCount = $plan['counts']['zero'];
    $skippedCount = $plan['counts']['skipped'];

    echo "Found " . count($plan['players']) . " active players\n\n";

    if ($dryRun) {
        foreach ($plan['players'] as $p) {
            echo "UserId {$p['userId']}: " . ($p['outcome'] === 'skipped'
                ? 'SKIPPED (eligible)'
                : $p['points'] . ' -> ' . $p['after']) . "\n";
        }
        echo "\n=== DRY RUN - no changes written ===\n";
        echo "Term: {$currentTermKey}\n";
        echo "Floored to {$floorValue}: {$flooredCount}\n";
        echo "Zeroed to 0:        {$zeroedCount}\n";
        echo "Skipped (eligible): {$skippedCount}\n";
        echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
        exit(0);
    }

    // Real run: everything in one transaction (api/grading.php
    // kbfRunSeasonReset): last_term_reset and every player locked and read
    // again, the floor rule applied, new-season awards kept, other counted
    // awards marked 0 and last_term_reset saved, all committed together.
    // If another run got there first, nothing changes.
    $result = kbfRunSeasonReset($pdo);
    if ($result['status'] !== 'reset') {
        echo "Nothing to do (" . $result['status'] . " for {$result['key']}).\n";
        echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
        kbfJobRunFinish($pdo, $runId, 'ok', 'Nothing to do (' . $result['status'] . ' for ' . $result['key'] . ')');
        exit(0);
    }
    $currentTermKey = $result['key'];
    $flooredCount = $result['counts']['floor'];
    $zeroedCount = $result['counts']['zero'];
    $skippedCount = $result['counts']['skipped'];
    $floorValue = $result['floorValue'];

    echo "\n=== Term Reset Complete ===\n";
    echo "Term: {$currentTermKey}\n";
    echo "Floored to {$floorValue}: {$flooredCount}\n";
    echo "Zeroed to 0:        {$zeroedCount}\n";
    echo "Skipped (eligible): {$skippedCount}\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

    $resetCount = $flooredCount + $zeroedCount;

    // season_reset_completed for admins (a problem never affects the reset)
    try {
        require_once dirname(__DIR__) . '/api/notify.php';
        notify('season_reset_completed', [
            'values' => [
                'term' => $currentTermKey,
                'count' => (string) $resetCount,
                'floored_count' => (string) $flooredCount,
                'zeroed_count' => (string) $zeroedCount,
                'skipped_count' => (string) $skippedCount,
                'floor_value' => (string) $floorValue,
            ],
            'relatedType' => 'season',
        ]);
    } catch (\Throwable $e) {
        error_log('term-reset: notification error: ' . $e->getMessage());
    }

    kbfJobRunFinish($pdo, $runId, 'ok',
        'Reset ' . $resetCount . ' player' . ($resetCount === 1 ? '' : 's') . ' for ' . $currentTermKey
        . ' (' . $flooredCount . ' to ' . $floorValue . ', ' . $zeroedCount . ' to 0, '
        . $skippedCount . ' eligible skipped)');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    kbfJobRunFinish($pdo, $runId, 'error', 'Error: ' . $e->getMessage());
    error_log('Term reset error: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
