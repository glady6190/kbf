<?php
/**
 * Make missing display versions and thumbnails (one-off, CLI only)
 * --------------------------------------------------------------------------
 * New uploads get a display version (<name>.d.jpg, at most 1600 px) and a
 * thumbnail (<name>.t.jpg, at most 480 px) straight away
 * (api/image-helpers.php). This job makes them for files uploaded before
 * that, in these folders:
 *     uploads/gallery (every tournament folder), uploads/announcements,
 *     uploads/profiles, uploads/registrations, uploads/tournaments
 *
 * Safe to stop and run again: a file whose two versions already exist is
 * skipped, so each run carries on where the last one stopped. The originals
 * are never changed.
 *
 * Usage (CLI only):
 *     php jobs/make-thumbnails.php               # up to 200 files, 50 s
 *     php jobs/make-thumbnails.php --limit=500   # up to 500 files per run
 *     php jobs/make-thumbnails.php --dry-run     # count only, write nothing
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

$root = dirname(__DIR__) . '/uploads';
$folders = ['gallery', 'announcements', 'profiles', 'registrations', 'tournaments'];

// Every original image (not a .d.jpg or .t.jpg) under the folders
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

$left = $todo - $made;
echo "\nMade: {$made}\nFailed: {$failed}\n{$left} still to do" . ($left > 0 && !$dryRun ? " (run again)" : "") . "\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
exit($failed > 0 ? 1 : 0);
