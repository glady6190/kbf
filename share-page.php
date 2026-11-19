<?php
/**
 * Link previews for shared pages (WhatsApp, Facebook and others)
 * --------------------------------------------------------------------------
 * Sharing apps do not run JavaScript, so the preview tags have to be in the
 * page as the server sends it. .htaccess sends post, tournament-details and
 * player-profile (with or without .html) here. This script reads the page's
 * own HTML file unchanged and inserts, just before </head>: og:title,
 * og:description (plain text, up to 200 characters), og:image (absolute
 * https address from the Website address in Site settings), og:image:width,
 * og:image:height, og:url, og:type, og:site_name "KBF-UK" and the matching
 * twitter: tags (summary_large_image).
 *
 * The picture is the post's, tournament's or player's share picture
 * (api/share-images.php), else the default one. Players under 18 always get
 * the default picture. Removed and deactivated players, and posts that are
 * hidden, drafts or scheduled, get the default picture and a generic title.
 * The page itself works exactly as before (it loads its data with
 * JavaScript, which shows "not found" for a wrong id). Security headers come
 * from .htaccess as for the .html files. If the database cannot be reached,
 * the page is sent with the generic tags.
 */

$pages = [
    'post' => 'post.html',
    'tournament-details' => 'tournament-details.html',
    'player-profile' => 'player-profile.html',
];
// The page comes from the address asked for (/post, /post.html, ...), so a
// second "page" in the query can never swap it; ?page= is the fallback
$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (preg_match('#^/(post|tournament-details|player-profile)(\.html)?$#', $requestPath, $m)) {
    $page = $m[1];
} else {
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
}
$file = isset($pages[$page]) ? __DIR__ . '/' . $pages[$page] : null;
$html = $file !== null && is_file($file) ? @file_get_contents($file) : false;
if ($html === false) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Page not found - KBF-UK</title></head>'
        . '<body><p>Page not found. <a href="/">Go to the home page</a></p></body></html>';
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Europe/London');

// ---------- Database (read only; no session is started) ----------
$pdo = null;
(function () {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
            putenv($key . '=' . trim($value));
            $_ENV[$key] = trim($value);
        }
    }
})();
try {
    if ((string) getenv('DB_NAME') !== '') {
        $pdo = new PDO(
            'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
} catch (PDOException $e) {
    error_log('share-page.php: database connection failed: ' . $e->getMessage());
    $pdo = null;
}
$GLOBALS['pdo'] = $pdo;

if (!function_exists('kbfLogOnce')) {
    function kbfLogOnce($message) {
        error_log('KBF: ' . $message);
    }
}
require_once __DIR__ . '/api/config-helpers.php';
require_once __DIR__ . '/api/share-images.php';

$base = rtrim($pdo ? kbfSiteBaseUrl() : 'https://kbf-uk.org', '/');

// Plain text, one line, at most $max characters
function kbfShareText($text, $max = 200) {
    $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h2|/h3|/li|/blockquote)\b[^>]*>#i', ' $0', (string) $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($text, 'UTF-8') > $max) {
        $text = rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…';
    }
    return $text;
}

// Generic tags (also for anything private or missing)
$generic = [
    'post' => ['News - KBF-UK', 'News, results and promotions from Kerala Badminton Federation UK.'],
    'tournament-details' => ['Tournament - KBF-UK', 'Badminton tournaments from Kerala Badminton Federation UK.'],
    'player-profile' => ['Player profile - KBF-UK', 'Players, grades and results at Kerala Badminton Federation UK.'],
];
list($title, $description) = $generic[$page];
$image = null;
$type = $page === 'post' ? 'article' : ($page === 'player-profile' ? 'profile' : 'website');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$url = $base . '/' . $page . ($id ? '?id=' . $id : '');

if ($pdo && $id) {
    try {
        if ($page === 'post') {
            $stmt = $pdo->prepare("
                SELECT Title, Content, ShareImagePath FROM Announcements
                WHERE AnnouncementId = ? AND IsPublished = 1 AND IsVisible = 1
                  AND (PostStatus IS NULL OR PostStatus = 'published')
            ");
            $stmt->execute([$id]);
            $post = $stmt->fetch();
            if ($post) {
                $private = false;
                // Promotion posts: nothing personal about a removed or deactivated player
                if (stripos((string) $post['Title'], 'Player Promotion:') !== false) {
                    $private = true;
                    if (preg_match('/Player ID:\s*([A-Za-z0-9\-]+)/i', (string) $post['Content'], $m)) {
                        $stmt = $pdo->prepare("SELECT IsActive, COALESCE(IsRemoved, 0) AS IsRemoved FROM Users WHERE PlayerId = ? LIMIT 1");
                        $stmt->execute([$m[1]]);
                        $player = $stmt->fetch();
                        $private = !$player || (int) $player['IsActive'] !== 1 || (int) $player['IsRemoved'] === 1;
                    }
                }
                if (!$private) {
                    $title = kbfShareText($post['Title'], 150);
                    $summary = kbfShareText($post['Content']);
                    if ($summary !== '') {
                        $description = $summary;
                    }
                    $image = $post['ShareImagePath'] ?? null;
                }
            }
        } elseif ($page === 'tournament-details') {
            $stmt = $pdo->prepare("
                SELECT Name, TournamentDate, Venue, Location, Description, ShareImagePath
                FROM Tournaments WHERE TournamentId = ? AND Status IN ('Approved', 'Completed')
            ");
            $stmt->execute([$id]);
            $t = $stmt->fetch();
            if ($t) {
                $title = kbfShareText($t['Name'], 150);
                $bits = [];
                if (!empty($t['TournamentDate'])) {
                    $bits[] = date('l j F Y', strtotime((string) $t['TournamentDate']));
                }
                $place = trim(implode(', ', array_filter([trim((string) $t['Venue']), trim((string) $t['Location'])])));
                if ($place !== '') {
                    $bits[] = $place;
                }
                $summary = implode(' · ', $bits);
                $more = kbfShareText($t['Description'] ?? '');
                $description = kbfShareText($summary . ($summary !== '' && $more !== '' ? '. ' : '') . $more);
                if ($description === '') {
                    $description = $generic[$page][1];
                }
                $image = $t['ShareImagePath'] ?? null;
            }
        } else {
            $stmt = $pdo->prepare("
                SELECT u.FirstName, u.LastName, u.PlayerId, u.YearOfBirth, u.IsActive,
                       COALESCE(u.IsRemoved, 0) AS IsRemoved, u.ShareImagePath, c.Name AS GradeName
                FROM Users u LEFT JOIN Categories c ON c.CategoryId = u.CategoryId
                WHERE u.UserId = ?
            ");
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if ($u && (int) $u['IsActive'] === 1 && (int) $u['IsRemoved'] === 0) {
                $name = kbfShareText(trim($u['FirstName'] . ' ' . $u['LastName']), 120);
                if ($name !== '') {
                    $title = $name . ' - KBF-UK';
                    $description = kbfShareText($name . (!empty($u['GradeName']) ? ', grade ' . $u['GradeName'] : '')
                        . (!empty($u['PlayerId']) ? ' (' . $u['PlayerId'] . ')' : '')
                        . '. Results and grading at Kerala Badminton Federation UK.');
                }
                // Never a photo of a player under 18
                if (!kbfIsUnder18($u['YearOfBirth'] ?? null)) {
                    $image = $u['ShareImagePath'] ?? null;
                }
            }
        }
    } catch (PDOException $e) {
        // A column not added yet, or the database is busy: generic tags
        error_log('share-page.php: ' . $e->getMessage());
        list($title, $description) = $generic[$page];
        $image = null;
    }
}

if (!kbfShareImageExists($image)) {
    $image = kbfEnsureDefaultShareImage();
}
$imageUrl = $base . $image;

$e = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$tags = "\n    <!-- Link previews (share-page.php) -->\n"
    . '    <meta property="og:title" content="' . $e($title) . "\">\n"
    . '    <meta property="og:description" content="' . $e($description) . "\">\n"
    . '    <meta property="og:image" content="' . $e($imageUrl) . "\">\n"
    . '    <meta property="og:image:width" content="' . KBF_SHARE_WIDTH . "\">\n"
    . '    <meta property="og:image:height" content="' . KBF_SHARE_HEIGHT . "\">\n"
    . '    <meta property="og:url" content="' . $e($url) . "\">\n"
    . '    <meta property="og:type" content="' . $e($type) . "\">\n"
    . "    <meta property=\"og:site_name\" content=\"KBF-UK\">\n"
    . "    <meta name=\"twitter:card\" content=\"summary_large_image\">\n"
    . '    <meta name="twitter:title" content="' . $e($title) . "\">\n"
    . '    <meta name="twitter:description" content="' . $e($description) . "\">\n"
    . '    <meta name="twitter:image" content="' . $e($imageUrl) . "\">\n";

$pos = stripos($html, '</head>');
if ($pos !== false) {
    $html = substr($html, 0, $pos) . ltrim($tags, "\n") . substr($html, $pos);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
