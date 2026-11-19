<?php
/**
 * One-off: turn HTML-encoded text already stored back into plain text
 * --------------------------------------------------------------------------
 * Names, cities, reasons, subjects and similar text used to be saved through
 * sanitizeInput() (htmlspecialchars), so "O'Brien" was stored as
 * "O&#039;Brien". The site now stores text as typed and escapes it only when
 * it is shown. This job decodes the rows saved the old way, once.
 *
 * Run it after the new PHP files are live (so nothing new is saved encoded).
 *
 * Safety:
 *   - Only values that look exactly like htmlspecialchars() output are
 *     changed: they contain at least one of &amp; &lt; &gt; &quot; &#039;
 *     (or &#39; / &apos;), every "&" starts one of those, and there is no raw
 *     < > " or ' (htmlspecialchars never leaves those). Anything else (plain
 *     text, a mix, other entities) is left as it is.
 *   - Each value is decoded once (html_entity_decode, ENT_QUOTES | ENT_HTML5).
 *   - Each finished column is recorded in Settings
 *     (decoded_text:<Table>.<Column>) and skipped on later runs, so a
 *     value that still looks encoded after one decode (someone typed
 *     "&amp;" literally) is never decoded a second time. --force ignores the
 *     records (not normally needed).
 *   - Each row is updated only if it still holds the value that was read.
 *   - Missing tables or columns are skipped.
 *
 * Usage (CLI only):
 *     php jobs/decode-stored-text.php             # decode
 *     php jobs/decode-stored-text.php --dry-run   # count and show examples, write nothing
 *     php jobs/decode-stored-text.php --force     # also redo columns already recorded as done
 *
 * Take a database backup first.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/api/config.php';

$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
const DECODE_BATCH_SIZE = 500;

// [table, primary key, [columns]]
$targets = [
    ['Users', 'UserId', ['FirstName', 'LastName', 'Location', 'PostCode']],
    ['PlayerRegistrations', 'RegistrationId', ['FirstName', 'LastName', 'Location', 'PostCode', 'ReviewComments']],
    ['PlayerRankingHistory', 'RankingHistoryId', ['Reason', 'Notes']],
    ['ApprovalLogs', 'LogId', ['ReferenceName', 'ReferenceDetails', 'ApprovedByAdminName', 'Comments']],
    ['EmailBroadcasts', 'BroadcastId', ['Subject']],
    ['Tournaments', 'TournamentId', ['Name', 'Venue', 'Location', 'PostCode', 'CategoriesOffered', 'OrganiserName', 'ContactName', 'Description']],
    ['Announcements', 'AnnouncementId', ['Title']],
    ['Clubs', 'ClubId', ['Name', 'Location']],
    ['Sponsors', 'SponsorId', ['Name', 'Description']],
    ['Matches', 'MatchId', ['GuestPlayer1Name', 'GuestPartner1Name', 'GuestPlayer2Name', 'GuestPartner2Name']],
    ['AuditLog', 'AuditId', ['Details']],
    ['UserActivityLogs', 'LogId', ['TargetUserName', 'ChangedByAdminName']],
];

// True when the value looks exactly like htmlspecialchars() output
function decodeLooksEncoded($value) {
    if (!is_string($value) || strpos($value, '&') === false) {
        return false;
    }
    if (preg_match('/[<>"\']/', $value)) {
        return false;
    }
    if (preg_match('/&(?!(?:amp|lt|gt|quot|apos|#0*39);)/', $value)) {
        return false;
    }
    return preg_match('/&(?:amp|lt|gt|quot|apos|#0*39);/', $value) === 1;
}

function decodeColumnExists(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function decodeDoneKey($table, $column) {
    return 'decoded_text:' . $table . '.' . $column;
}

function decodeIsDone(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT SettingValue FROM Settings WHERE SettingKey = ?");
    $stmt->execute([decodeDoneKey($table, $column)]);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== '';
}

function decodeMarkDone(PDO $pdo, $table, $column, $changed) {
    $stmt = $pdo->prepare("
        INSERT INTO Settings (SettingKey, SettingValue, Description) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
    ");
    $stmt->execute([
        decodeDoneKey($table, $column),
        date('Y-m-d H:i:s') . '; ' . (int) $changed . ' rows',
        'jobs/decode-stored-text.php decoded this column once; it is not decoded again',
    ]);
}

echo "Decode stored text" . ($dryRun ? " [DRY RUN]" : "") . ($force ? " [FORCE]" : "") . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$totalChanged = 0;
$totalSkippedMixed = 0;

foreach ($targets as [$table, $pk, $columns]) {
    foreach ($columns as $column) {
        $label = $table . '.' . $column;
        try {
            if (!decodeColumnExists($pdo, $table, $column) || !decodeColumnExists($pdo, $table, $pk)) {
                echo sprintf("%-40s skipped (no such column)\n", $label);
                continue;
            }
            if (!$force && decodeIsDone($pdo, $table, $column)) {
                echo sprintf("%-40s skipped (already decoded)\n", $label);
                continue;
            }

            $lastId = 0;
            $changed = 0;
            $mixed = 0;
            $examples = [];
            // Identifiers come from the fixed list above, never from input
            $select = $pdo->prepare("
                SELECT `$pk` AS RowId, `$column` AS Value FROM `$table`
                WHERE `$pk` > ? AND `$column` LIKE '%&%'
                ORDER BY `$pk` LIMIT " . DECODE_BATCH_SIZE);
            $update = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE `$pk` = ? AND `$column` = ?");

            do {
                $select->execute([$lastId]);
                $rows = $select->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $lastId = (int) $row['RowId'];
                    $value = (string) $row['Value'];
                    if (!decodeLooksEncoded($value)) {
                        $mixed++;
                        continue;
                    }
                    $plain = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($plain === $value) {
                        continue;
                    }
                    if (count($examples) < 3) {
                        $examples[] = mb_substr($value, 0, 60) . '  ->  ' . mb_substr($plain, 0, 60);
                    }
                    if (!$dryRun) {
                        $update->execute([$plain, $row['RowId'], $value]);
                        $changed += $update->rowCount();
                    } else {
                        $changed++;
                    }
                }
            } while (count($rows) === DECODE_BATCH_SIZE);

            if (!$dryRun) {
                decodeMarkDone($pdo, $table, $column, $changed);
            }
            echo sprintf("%-40s %d %s, %d left as they are (not plain encoded text)\n",
                $label, $changed, $dryRun ? 'would be decoded' : 'decoded', $mixed);
            foreach ($examples as $example) {
                echo "      e.g. " . $example . "\n";
            }
            $totalChanged += $changed;
            $totalSkippedMixed += $mixed;
        } catch (PDOException $e) {
            echo sprintf("%-40s ERROR: %s\n", $label, $e->getMessage());
        }
    }
}

echo "\nTotal: " . $totalChanged . ($dryRun ? " values would be decoded" : " values decoded")
    . "; " . $totalSkippedMixed . " values with '&' left as they are.\n";
echo $dryRun ? "Dry run: nothing was written.\n" : "Done.\n";
