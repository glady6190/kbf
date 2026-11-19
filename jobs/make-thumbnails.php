<?php
/**
 * Make missing display versions and thumbnails (one-off, CLI only)
 * --------------------------------------------------------------------------
 * New uploads get a display version (<name>.d.webp, at most 1600 px) and a
 * thumbnail (<name>.t.webp, at most 480 px) straight away
 * (api/image-helpers.php; .jpg instead when the server's GD cannot write
 * WebP). This job makes them for files uploaded before that, including the
 * WebP versions of images that so far only have JPEG ones, in these folders:
 *     uploads/gallery (every tournament folder), uploads/announcements,
 *     uploads/profiles, uploads/registrations, uploads/tournaments,
 *     assets/images/sponsors
 * Pages ask for the WebP version first, then the JPEG one, then the original.
 *
 * It also makes the share pictures used by link previews (1200 x 630 JPEG,
 * api/share-images.php) for existing posts, tournaments and players that
 * have none yet, and the default share picture when it is missing.
 *
 * Safe to stop and run again: a file whose two versions already exist is
 * skipped, so each run carries on where the last one stopped. The originals
 * are never changed.
 *
 * Usage (CLI only):
 *     php jobs/make-thumbnails.php               # up to 200 files, 50 s
 *     php jobs/make-thumbnails.php --limit=500   # up to 500 files per run
 *     php jobs/make-thumbnails.php --dry-run     # count only, write nothing
 *     php jobs/make-thumbnails.php --refresh-default  # also remake the default share picture
 * Run it until it reports "0 still to do".
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

date_default_timezone_set('Europe/London');
require_once dirname(__DIR__) . '/api/image-helpers.php';

$dryRun = in_array('--dry-run', $argv, true);
$limit = 200;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d{1,5})$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}
$timeBudget = 50; // seconds per run, so a shared host never kills it midway
$started = time();

$root = dirname(__DIR__);
$folders = ['uploads/gallery', 'uploads/announcements', 'uploads/profiles', 'uploads/registrations', 'uploads/tournaments',
            'assets/images/sponsors'];

// Every original image (not a .d.* or .t.* version) under the folders
function kbfThumbOriginals($dir) {
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        if ($item->isFile() && kbfImageVariantFiles($item->getPathname()) !== null) {
            $out[] = $item->getPathname();
        }
    }
    sort($out);
    return $out;
}

echo "Making display versions and thumbnails" . ($dryRun ? " [DRY RUN]" : "") . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$todo = 0;
$made = 0;
$failed = 0;
$stopped = false;
foreach ($folders as $folder) {
    foreach (kbfThumbOriginals($root . '/' . $folder) as $file) {
        list($display, $thumb) = kbfImageVariantFiles($file);
        if (is_file($display) && is_file($thumb)) {
            continue;
        }
        $todo++;
        if ($dryRun || $stopped) {
            continue;
        }
        if ($made + $failed >= $limit || time() - $started >= $timeBudget) {
            $stopped = true;
            continue;
        }
        if (kbfMakeImageVariants($file)) {
            $made++;
        } else {
            $failed++;
            echo "Could not make versions of " . substr($file, strlen($root)) . "\n";
        }
    }
}

// ============================================
// SHARE PICTURES (link previews, api/share-images.php): the default one
// when missing, then one for each post with a main picture, each tournament
// with a poster and each active player with a photo (never under 18) that
// has none yet. Needs database/news-editor.sql (ShareImagePath).
// ============================================
require_once $root . '/api/config.php';
require_once $root . '/api/share-images.php';
if (!$dryRun) {
    // --refresh-default: made again even when it exists
    kbfEnsureDefaultShareImage(in_array('--refresh-default', $argv, true));
    echo is_file($root . KBF_SHARE_DEFAULT) ? "Default share picture: ok\n" : "Default share picture: could not be made\n";
}
$shareSources = [
    'post' => "SELECT AnnouncementId AS Id, FeaturedImage AS Source, ShareImagePath FROM Announcements
               WHERE FeaturedImage LIKE '/uploads/announcements/%' ORDER BY AnnouncementId",
    'tournament' => "SELECT TournamentId AS Id, PosterPath AS Source, ShareImagePath FROM Tournaments
                     WHERE PosterPath IS NOT NULL AND PosterPath <> '' ORDER BY TournamentId",
    'player' => "SELECT UserId AS Id, ProfilePhoto AS Source, ShareImagePath FROM Users
                 WHERE ProfilePhoto IS NOT NULL AND ProfilePhoto <> '' AND IsActive = 1 AND COALESCE(IsRemoved, 0) = 0
                   AND NOT (YearOfBirth IS NOT NULL AND YearOfBirth > 0 AND YearOfBirth >= " . kbfUnder18FromYear() . ")
                 ORDER BY UserId",
];
foreach ($shareSources as $kind => $sql) {
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        echo "Share pictures ({$kind}): skipped, run database/news-editor.sql first\n";
        continue;
    }
    foreach ($rows as $row) {
        if (kbfShareImageExists($row['ShareImagePath']) || kbfShareSourceFile($row['Source']) === null) {
            continue;
        }
        $todo++;
        if ($dryRun || $stopped) {
            continue;
        }
        if ($made + $failed >= $limit || time() - $started >= $timeBudget) {
            $stopped = true;
            continue;
        }
        if (kbfUpdateShareImage($pdo, $kind, (int) $row['Id'], $row['Source']) !== null) {
            $made++;
        } else {
            $failed++;
            echo "Could not make the share picture of {$kind} " . (int) $row['Id'] . "\n";
        }
    }
}

$left = $todo - $made;
echo "\nMade: {$made}\nFailed: {$failed}\n{$left} still to do" . ($left > 0 && !$dryRun ? " (run again)" : "") . "\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
exit($failed > 0 ? 1 : 0);
