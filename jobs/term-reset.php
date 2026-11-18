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
 * Usage (CLI only):
 *     php jobs/term-reset.php            # perform the reset
 *     php jobs/term-reset.php --dry-run  # show what WOULD change, write nothing
 *
 * Only Users.TotalPoints is ever modified. PlayerPointsHistory, CareerPoints
 * and CategoryId are never touched.
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

// Write last_term_reset; the row is created by database/grading-settings.sql,
// and created here too if it is still missing
function termResetSaveKey($pdo, $key) {
    $stmt = $pdo->prepare("
        INSERT INTO Settings (SettingKey, SettingValue) VALUES ('last_term_reset', ?)
        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
    ");
    $stmt->execute([$key]);
    return true;
}

try {
    // ============================================
    // 1. SETTINGS AND PLAN (shared rules)
    // ============================================
    $settings = kbfSeasonSettings($pdo);
    $plan = kbfSeasonPlan($pdo, $settings, null, true);
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
        termResetSaveKey($pdo, $currentTermKey);
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

    // Real run: apply all point updates in a single transaction
    $pdo->beginTransaction();
    $updateStmt = $pdo->prepare("UPDATE Users SET TotalPoints = ? WHERE UserId = ?");
    foreach ($plan['players'] as $p) {
        if ($p['outcome'] !== 'skipped') {
            $updateStmt->execute([$p['after'], $p['userId']]);
        }
    }
    $pdo->commit();

    // Record the term just processed so it is not reset again
    termResetSaveKey($pdo, $currentTermKey);

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
