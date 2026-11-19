<?php
/**
 * File versions for the site's own CSS and JS (cache-safe links)
 * --------------------------------------------------------------------------
 * Every link from a page to the site's own stylesheets and scripts
 * (/assets/... .css and /js/... .js) carries ?v=<version>, so browsers fetch
 * fresh copies after each deployment while the .htaccess caching rules stay
 * as they are.
 *
 * The version is kept in one place: build/asset-version.txt. This script
 * writes it into every .html page of the site (only the ?v= part of those
 * links changes; each file keeps its line endings).
 *
 * Usage (CLI only, on the PC, before the commit that is packaged):
 *     php build/asset-version.php            # new version: build date and time (YYMMDDHHMM)
 *     php build/asset-version.php 2611191500 # a version of your choice (letters, digits, . _ -)
 *     php build/asset-version.php --keep     # stamp the version already in asset-version.txt
 * See docs/BUILD.md. The build/ folder is not uploaded.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/London');
$root = dirname(__DIR__);
$versionFile = __DIR__ . '/asset-version.txt';

$arg = $argv[1] ?? null;
if ($arg === '--keep') {
    $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
    if ($version === '') {
        fwrite(STDERR, "build/asset-version.txt is empty; run without --keep to make a version.\n");
        exit(1);
    }
} elseif ($arg !== null) {
    $version = $arg;
} else {
    $version = date('ymdHi');
}
if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version)) {
    fwrite(STDERR, "Invalid version: use 1-32 letters, digits, dots, dashes or underscores.\n");
    exit(1);
}
file_put_contents($versionFile, $version . "\n");

// Every .html page, outside folders that are not part of the site
$skip = ['.git', '.claude', 'vendor', 'tools', 'docs', 'database', 'storage', 'uploads', 'build', 'node_modules'];
$pages = [];
$items = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($item) use ($skip, $root) {
            if ($item->isDir()) {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                return !in_array(explode('/', $relative)[0], $skip, true);
            }
            return strtolower($item->getExtension()) === 'html';
        }
    )
);
foreach ($items as $item) {
    $pages[] = $item->getPathname();
}
sort($pages);

// src="/js/x.js", href="/assets/css/x.css" (with or without an old ?v=)
$pattern = '#((?:src|href)=["\'])(/(?:assets|js)/[A-Za-z0-9_./-]+\.(?:css|js))(?:\?v=[A-Za-z0-9._-]*)?(["\'])#';
$changedFiles = 0;
$links = 0;
foreach ($pages as $page) {
    $html = file_get_contents($page);
    $new = preg_replace_callback($pattern, function ($m) use ($version, &$links) {
        $links++;
        return $m[1] . $m[2] . '?v=' . $version . $m[3];
    }, $html);
    if ($new !== null && $new !== $html) {
        file_put_contents($page, $new);
        $changedFiles++;
    }
}

echo "Version {$version}: {$links} link(s) in " . count($pages) . " page(s), {$changedFiles} file(s) changed.\n";
