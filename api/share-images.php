<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }

// ============================================
// SHARE PICTURES (link previews on WhatsApp, Facebook and others)
// A 1200 x 630 JPEG made with GD: the post's main picture, the tournament's
// poster or the player's photo, shown whole (never cropped) and centred on
// the site's dark background (#0F172A), with the KBF logo small in the
// bottom right corner. Saved in uploads/share/ with a random name; its path
// is stored in ShareImagePath (Announcements, Tournaments, Users).
//
// The default share picture (the KBF logo centred, with "Kerala Badminton
// Federation UK") is used by every page without its own picture, and
// always for players under 18. It lives at a fixed path and is made when
// missing (share-page.php, jobs/make-thumbnails.php).
//
// Used by api/announcements.php, api/tournaments.php,
// api/upload-profile-photo.php, api/approvals.php, share-page.php and
// jobs/make-thumbnails.php.
// ============================================

const KBF_SHARE_URL_DIR = '/uploads/share';
const KBF_SHARE_DEFAULT = '/uploads/share/kbf-default.jpg';
const KBF_SHARE_WIDTH = 1200;
const KBF_SHARE_HEIGHT = 630;
const KBF_SHARE_LOGO = '/assets/images/logo/KBF-Logo.jpeg';

function kbfShareRoot() {
    return dirname(__DIR__);
}

// Absolute file path of a site path such as /uploads/x.webp, only for
// plain files under uploads/ or assets/images/ (never anything else)
function kbfShareSourceFile($urlPath) {
    if (!is_string($urlPath) || !preg_match('#^/(uploads|assets/images)/[A-Za-z0-9_./-]+\.(jpe?g|png|webp|gif)$#i', $urlPath)
        || strpos($urlPath, '..') !== false) {
        return null;
    }
    $file = kbfShareRoot() . $urlPath;
    return is_file($file) ? $file : null;
}

// True when the stored share picture exists
function kbfShareImageExists($urlPath) {
    return is_string($urlPath) && preg_match('#^' . KBF_SHARE_URL_DIR . '/[A-Za-z0-9_-]+\.jpg$#', $urlPath)
        && is_file(kbfShareRoot() . $urlPath);
}

// A TrueType font from the server, for the default picture's text (none
// is shipped with the site). Null: GD's built-in font is used, enlarged.
function kbfShareFont() {
    static $font = false;
    if ($font !== false) {
        return $font;
    }
    $font = null;
    if (!function_exists('imagettftext')) {
        return $font;
    }
    $candidates = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/liberation-sans/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
    ];
    foreach ($candidates as $path) {
        if (@is_file($path) && @is_readable($path)) {
            $font = $path;
            break;
        }
    }
    return $font;
}

// A new 1200 x 630 canvas filled with the dark background
function kbfShareCanvas() {
    $canvas = imagecreatetruecolor(KBF_SHARE_WIDTH, KBF_SHARE_HEIGHT);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 0x0F, 0x17, 0x2A));
    return $canvas;
}

// Copy $source onto $canvas, whole, scaled to fit the box and centred in
// it (scaled down, or up at most 2x so a small photo is not lost)
function kbfShareFit($canvas, $source, $boxX, $boxY, $boxW, $boxH, $maxScale = 2.0) {
    $w = imagesx($source);
    $h = imagesy($source);
    $scale = min($boxW / $w, $boxH / $h, $maxScale);
    $newW = max(1, (int) round($w * $scale));
    $newH = max(1, (int) round($h * $scale));
    $x = $boxX + (int) floor(($boxW - $newW) / 2);
    $y = $boxY + (int) floor(($boxH - $newH) / 2);
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $source, $x, $y, 0, 0, $newW, $newH, $w, $h);
    return [$x, $y, $newW, $newH];
}

// The logo (a white square) at $size px, top left at ($x, $y)
function kbfShareDrawLogo($canvas, $x, $y, $size) {
    $file = kbfShareSourceFile(KBF_SHARE_LOGO);
    if ($file === null || !function_exists('kbfOpenImage')) {
        return false;
    }
    $logo = kbfOpenImage($file, 'image/jpeg');
    if (!$logo) {
        return false;
    }
    $white = imagecolorallocate($canvas, 0xFF, 0xFF, 0xFF);
    imagefilledrectangle($canvas, $x, $y, $x + $size - 1, $y + $size - 1, $white);
    imagecopyresampled($canvas, $logo, $x, $y, 0, 0, $size, $size, imagesx($logo), imagesy($logo));
    imagedestroy($logo);
    return true;
}

// Write $canvas as a JPEG at a new random path in uploads/share.
// Returns the site path, or null.
function kbfShareSave($canvas, $fixedUrlPath = null) {
    $dir = kbfShareRoot() . KBF_SHARE_URL_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }
    $urlPath = $fixedUrlPath ?? (KBF_SHARE_URL_DIR . '/share_' . bin2hex(random_bytes(12)) . '.jpg');
    $file = kbfShareRoot() . $urlPath;
    $tmp = $file . '.tmp';
    imageinterlace($canvas, true);
    $ok = @imagejpeg($canvas, $tmp, 85);
    if (!$ok || !@rename($tmp, $file)) {
        @unlink($tmp);
        return null;
    }
    return $urlPath;
}

// Make a share picture from a site image path (/uploads/...). Returns the
// new site path, or null when it could not be made.
function kbfMakeShareImage($sourceUrlPath) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return null;
    }
    require_once __DIR__ . '/image-helpers.php';
    $file = kbfShareSourceFile($sourceUrlPath);
    if ($file === null) {
        return null;
    }
    try {
        $info = @getimagesize($file);
        if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > 50000000) {
            return null;
        }
        $source = kbfOpenImage($file, (string) ($info['mime'] ?? ''));
        if (!$source) {
            return null;
        }
        $canvas = kbfShareCanvas();
        // The picture fills the height (with a small margin) and is centred
        kbfShareFit($canvas, $source, 24, 24, KBF_SHARE_WIDTH - 48, KBF_SHARE_HEIGHT - 48);
        imagedestroy($source);
        // The logo, small, bottom right
        kbfShareDrawLogo($canvas, KBF_SHARE_WIDTH - 24 - 84, KBF_SHARE_HEIGHT - 24 - 84, 84);
        $path = kbfShareSave($canvas);
        imagedestroy($canvas);
        return $path;
    } catch (Throwable $e) {
        error_log('kbfMakeShareImage: ' . $e->getMessage());
        return null;
    }
}

// Text centred at $centreY, in the TrueType font when there is one, else
// GD's built-in font enlarged
function kbfShareDrawText($canvas, $text, $centreY, $sizePx, $color) {
    $font = kbfShareFont();
    if ($font !== null) {
        $box = @imagettfbbox($sizePx, 0, $font, $text);
        if ($box !== false) {
            $width = abs($box[2] - $box[0]);
            $height = abs($box[7] - $box[1]);
            $x = (int) round((KBF_SHARE_WIDTH - $width) / 2);
            $y = (int) round($centreY + $height / 2);
            @imagettftext($canvas, $sizePx, 0, $x, $y, $color, $font, $text);
            return;
        }
    }
    // Built-in font 5 (9 x 15 px), drawn small and enlarged
    $charW = imagefontwidth(5);
    $charH = imagefontheight(5);
    $smallW = $charW * strlen($text);
    $small = imagecreatetruecolor($smallW, $charH);
    $bg = imagecolorallocate($small, 0x0F, 0x17, 0x2A);
    imagefill($small, 0, 0, $bg);
    $rgb = imagecolorsforindex($canvas, $color);
    imagestring($small, 5, 0, 0, $text, imagecolorallocate($small, $rgb['red'], $rgb['green'], $rgb['blue']));
    $scale = max(1, (int) floor($sizePx / $charH * 1.4));
    $scale = min($scale, (int) floor((KBF_SHARE_WIDTH - 80) / max(1, $smallW)));
    $scale = max(1, $scale);
    $w = $smallW * $scale;
    $h = $charH * $scale;
    imagecopyresized($canvas, $small, (int) round((KBF_SHARE_WIDTH - $w) / 2), (int) round($centreY - $h / 2), 0, 0, $w, $h, $smallW, $charH);
    imagedestroy($small);
}

// The default share picture: made when missing ($force: made again).
// Returns its site path (even when it could not be made, so pages always
// name the same address).
function kbfEnsureDefaultShareImage($force = false) {
    $file = kbfShareRoot() . KBF_SHARE_DEFAULT;
    if (!$force && is_file($file)) {
        return KBF_SHARE_DEFAULT;
    }
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return KBF_SHARE_DEFAULT;
    }
    require_once __DIR__ . '/image-helpers.php';
    try {
        $canvas = kbfShareCanvas();
        kbfShareDrawLogo($canvas, (int) ((KBF_SHARE_WIDTH - 360) / 2), 70, 360);
        $white = imagecolorallocate($canvas, 0xFF, 0xFF, 0xFF);
        kbfShareDrawText($canvas, 'Kerala Badminton Federation UK', 520, 40, $white);
        kbfShareSave($canvas, KBF_SHARE_DEFAULT);
        imagedestroy($canvas);
    } catch (Throwable $e) {
        error_log('kbfEnsureDefaultShareImage: ' . $e->getMessage());
    }
    return KBF_SHARE_DEFAULT;
}

// Delete a stored share picture (only ever a share_*.jpg in uploads/share)
function kbfDeleteShareImage($urlPath) {
    if (is_string($urlPath) && preg_match('#^' . KBF_SHARE_URL_DIR . '/share_[A-Za-z0-9]+\.jpg$#', $urlPath)) {
        $file = kbfShareRoot() . $urlPath;
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

// Make (or clear) the share picture of one row and store its path.
// $kind: 'post', 'tournament' or 'player'. $sourceUrlPath: the picture to
// use (null or '': the row has none, the stored one is removed).
// Returns the new path or null. A problem never stops the caller.
function kbfUpdateShareImage($pdo, $kind, $id, $sourceUrlPath) {
    $tables = [
        'post' => ['Announcements', 'AnnouncementId'],
        'tournament' => ['Tournaments', 'TournamentId'],
        'player' => ['Users', 'UserId'],
    ];
    if (!isset($tables[$kind]) || (int) $id < 1) {
        return null;
    }
    list($table, $idCol) = $tables[$kind];
    try {
        $stmt = $pdo->prepare("SELECT ShareImagePath FROM $table WHERE $idCol = ?");
        $stmt->execute([(int) $id]);
        $old = $stmt->fetchColumn();
    } catch (PDOException $e) {
        // ShareImagePath not added yet (database/news-editor.sql)
        return null;
    }
    if ($old === false) {
        return null;
    }
    $new = (is_string($sourceUrlPath) && $sourceUrlPath !== '') ? kbfMakeShareImage($sourceUrlPath) : null;
    try {
        $stmt = $pdo->prepare("UPDATE $table SET ShareImagePath = ? WHERE $idCol = ?");
        $stmt->execute([$new, (int) $id]);
    } catch (PDOException $e) {
        error_log('kbfUpdateShareImage: ' . $e->getMessage());
        kbfDeleteShareImage($new);
        return null;
    }
    if (is_string($old) && $old !== '' && $old !== $new) {
        kbfDeleteShareImage($old);
    }
    return $new;
}
?>
