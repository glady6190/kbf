<?php
/**
 * One-off: remove emoji and symbols from the start of news post titles
 * --------------------------------------------------------------------------
 * Older posts (for example the automatic promotion posts) were created with
 * an emoji such as the trophy at the start of the title. New posts are
 * created without them. This job removes emoji and symbol characters (and
 * the spaces around them) from the START of every existing post title, and
 * reports how many titles it changed. Nothing else in a title is touched.
 *
 * Safety:
 *   - Only a leading run of emoji and symbols is removed: symbol characters
 *     (Unicode "Other symbol", which includes emoji and flags), keycaps,
 *     skin tones, variation selectors and joiners. Letters, digits,
 *     punctuation and currency (for example "£20 entry") are never removed.
 *   - A title that would become empty is left as it is (and listed).
 *   - Each row is updated only if it still holds the title that was read.
 *   - Safe to run twice: a cleaned title no longer starts with a symbol,
 *     so a second run changes nothing.
 *
 * Usage (CLI only):
 *     php jobs/clean-titles.php             # clean
 *     php jobs/clean-titles.php --dry-run   # count and show examples, write nothing
 *
 * Take a database backup first.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/api/config.php';

$dryRun = in_array('--dry-run', $argv, true);
const CLEAN_TITLES_BATCH_SIZE = 500;

// A leading run of keycaps, symbols, emoji parts and spaces
const CLEAN_TITLES_LEADING = '/^(?:[0-9#*]\x{FE0F}?\x{20E3}|[\p{So}\p{Me}\x{FE00}-\x{FE0F}\x{200D}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}]|\s)+/u';

// The title without its leading emoji and symbols, or null when it has none
function cleanTitleStart($title) {
    if (!is_string($title) || $title === '') {
        return null;
    }
    if (!preg_match(CLEAN_TITLES_LEADING, $title, $m)) {
        return null;
    }
    // Spaces alone are not a reason to change a title
    if (trim($m[0]) === '') {
        return null;
    }
    $clean = trim((string) preg_replace(CLEAN_TITLES_LEADING, '', $title));
    return $clean === $title ? null : $clean;
}

echo "Clean news post titles" . ($dryRun ? " [DRY RUN]" : "") . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$changed = 0;
$leftEmpty = [];
$examples = [];

try {
    $select = $pdo->prepare("
        SELECT AnnouncementId, Title FROM Announcements
        WHERE AnnouncementId > ?
        ORDER BY AnnouncementId LIMIT " . CLEAN_TITLES_BATCH_SIZE);
    $update = $pdo->prepare("UPDATE Announcements SET Title = ? WHERE AnnouncementId = ? AND Title = ?");

    $lastId = 0;
    do {
        $select->execute([$lastId]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $lastId = (int) $row['AnnouncementId'];
            $title = (string) $row['Title'];
            $clean = cleanTitleStart($title);
            if ($clean === null) {
                continue;
            }
            if ($clean === '') {
                $leftEmpty[] = $lastId;
                continue;
            }
            if (count($examples) < 5) {
                $examples[] = 'Post ' . $lastId . ': ' . mb_substr($title, 0, 60) . '  ->  ' . mb_substr($clean, 0, 60);
            }
            if ($dryRun) {
                $changed++;
            } else {
                $update->execute([$clean, $lastId, $title]);
                $changed += $update->rowCount();
            }
        }
    } while (count($rows) === CLEAN_TITLES_BATCH_SIZE);
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($examples as $example) {
    echo "  e.g. " . $example . "\n";
}
if ($leftEmpty) {
    echo "\nLeft as they are (the title is only emoji or symbols): post " . implode(', ', $leftEmpty) . "\n";
}
echo "\n" . $changed . ($changed === 1 ? " title" : " titles") . ($dryRun ? " would be changed" : " changed") . ".\n";
echo $dryRun ? "Dry run: nothing was written.\n" : "Done.\n";
