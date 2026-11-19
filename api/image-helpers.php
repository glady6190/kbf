<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }

// ============================================
// SHARED IMAGE UPLOADS (news images, gallery photos)
// The same checks as tournament posters: the real type is read with finfo
// and getimagesize, the extension comes from the detected type, the file
// name is random and the size limit comes from Site settings. Every image is
// then re-encoded with GD (as profile photos are), which removes location,
// camera and other EXIF data. Error messages never include the file name.
// ============================================

const KBF_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

// Largest width or height kept (bigger images are scaled down)
const KBF_IMAGE_MAX_SIDE = 2400;

// Check and re-encode one image file into $destDir with a random name.
// $sourcePath: an uploaded temp file ($isUploaded = true) or a temp file
// written by the site. Returns [url path or null, error or null].
function kbfStoreImage($sourcePath, $isUploaded, $destDir, $urlDir, $prefix, $maxBytes, $label = 'Image') {
    if (!is_string($sourcePath) || $sourcePath === '' || !is_file($sourcePath)) {
        return [null, $label . ' upload failed'];
    }
    if ($isUploaded && !is_uploaded_file($sourcePath)) {
        return [null, 'Invalid upload'];
    }
    $size = (int) @filesize($sourcePath);
    if ($size < 1) {
        return [null, $label . ' upload failed'];
    }
    if ($size > $maxBytes) {
        return [null, $label . ' too large. Maximum ' . round($maxBytes / 1048576, 1) . ' MB'];
    }

    if (!function_exists('finfo_open')) {
        return [null, 'Images cannot be checked on this server'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = (string) finfo_file($finfo, $sourcePath);
    finfo_close($finfo);
    if (!isset(KBF_IMAGE_TYPES[$mimeType])) {
        return [null, 'Only JPG, PNG, WebP and GIF images are allowed'];
    }

    $info = @getimagesize($sourcePath);
    if ($info === false || ($info['mime'] ?? '') !== $mimeType || (int) $info[0] < 1 || (int) $info[1] < 1) {
        return [null, 'Invalid image file'];
    }
    // Refuse absurd sizes before decoding (memory)
    if ((int) $info[0] * (int) $info[1] > 50000000) {
        return [null, 'The image is too large to process'];
    }

    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
        return [null, 'Failed to create upload directory'];
    }

    // Saved as WebP (or the fallback format, see kbfImageOutputFormat)
    $base = rtrim($destDir, '/\\') . '/' . $prefix . bin2hex(random_bytes(16));
    $target = kbfReencodeImage($sourcePath, $base, $mimeType);
    if ($target === null) {
        return [null, 'The image could not be processed. Please try a different image.'];
    }
    if ($isUploaded) {
        @unlink($sourcePath);
    }
    // Display version and thumbnail next to it (a problem never stops the upload)
    kbfMakeImageVariants($target);
    return [rtrim($urlDir, '/') . '/' . basename($target), null];
}

// ============================================
// OUTPUT FORMAT: WebP for every saved image (original, display version and
// thumbnail), keeping transparency. When the server's GD cannot write WebP,
// JPEG is used instead, or PNG for an image with transparency, and this is
// logged once a day.
// ============================================
function kbfWebpWritable() {
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('imagewebp') && function_exists('gd_info') && !empty(gd_info()['WebP Support']);
        if (!$ok) {
            $message = 'GD on this server cannot write WebP images; uploads are saved as JPEG (PNG when transparent) instead.';
            if (function_exists('kbfLogOnce')) {
                kbfLogOnce($message);
            } else {
                error_log('KBF: ' . $message);
            }
        }
    }
    return $ok;
}

// True when a GD image has any (partly) transparent pixel. Checked on a
// small copy, so it is quick even for large images.
function kbfImageHasAlpha($image) {
    $width = imagesx($image);
    $height = imagesy($image);
    $sw = min(64, $width);
    $sh = min(64, $height);
    $small = imagecreatetruecolor($sw, $sh);
    imagealphablending($small, false);
    imagesavealpha($small, true);
    imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
    imagecopyresampled($small, $image, 0, 0, 0, 0, $sw, $sh, $width, $height);
    $found = false;
    for ($y = 0; $y < $sh && !$found; $y++) {
        for ($x = 0; $x < $sw; $x++) {
            if (((imagecolorat($small, $x, $y) >> 24) & 0x7F) > 0) {
                $found = true;
                break;
            }
        }
    }
    imagedestroy($small);
    return $found;
}

// 'webp', or the fallback: 'png' for a transparent image, otherwise 'jpg'
function kbfImageOutputFormat($image, $mayHaveAlpha) {
    if (kbfWebpWritable()) {
        return 'webp';
    }
    return ($mayHaveAlpha && kbfImageHasAlpha($image)) ? 'png' : 'jpg';
}

// Write a GD image in $format ('webp', 'png' or 'jpg'). JPEG has no
// transparency, so transparent areas are put on white for it.
function kbfWriteImage($image, $target, $format, $quality) {
    if ($format === 'webp') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        return imagewebp($image, $target, $quality) && is_file($target);
    }
    if ($format === 'png') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        return imagepng($image, $target, 8) && is_file($target);
    }
    $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagealphablending($flat, true);
    imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
    imageinterlace($flat, true);
    $ok = imagejpeg($flat, $target, $quality);
    imagedestroy($flat);
    return (bool) $ok && is_file($target);
}

// Open an image file with GD by its real type, or false
function kbfOpenImage($file, $mimeType = null) {
    if ($mimeType === null) {
        $info = @getimagesize($file);
        $mimeType = $info === false ? '' : (string) ($info['mime'] ?? '');
    }
    switch ($mimeType) {
        case 'image/jpeg': return @imagecreatefromjpeg($file);
        case 'image/png': return @imagecreatefrompng($file);
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false;
        case 'image/gif': return @imagecreatefromgif($file);
    }
    return false;
}

// Re-save an image file that is already on disk (for example a resized
// profile photo or a poster) in the output format, next to it with the same
// random name, and delete the old file. Returns the new path, or the old
// path unchanged if it could not be converted (it is still a valid image).
function kbfConvertStoredImage($file) {
    if (!is_file($file) || !function_exists('imagecreatetruecolor')) {
        return $file;
    }
    $info = @getimagesize($file);
    if ($info === false || (int) $info[0] * (int) $info[1] > 50000000) {
        return $file;
    }
    $mime = (string) ($info['mime'] ?? '');
    $image = kbfOpenImage($file, $mime);
    if (!$image) {
        return $file;
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $format = kbfImageOutputFormat($image, $mime !== 'image/jpeg');
    $base = preg_replace('/\.(jpe?g|png|webp|gif)$/i', '', $file);
    $target = $base . '.' . $format;
    if ($target === $file) {
        imagedestroy($image);
        return $file;
    }
    $ok = kbfWriteImage($image, $target, $format, 88);
    imagedestroy($image);
    if (!$ok) {
        @unlink($target);
        return $file;
    }
    @unlink($file);
    return $target;
}

// ============================================
// DISPLAY VERSION AND THUMBNAIL
// For /uploads/<folder>/<name>.<ext> the site also keeps
//   <name>.d.webp  display version, at most 1600 px on its longest side
//   <name>.t.webp  thumbnail, at most 480 px
// (.d.jpg / .t.jpg instead on a server whose GD cannot write WebP, and for
// images saved before WebP was used). Lists, grids and cards use the
// thumbnail; full-size views use the display version (js/escape.js
// imageVariant() tries WebP, then JPEG, then the original). The random name
// of the original is kept. WebP keeps transparency; JPEG puts it on white.
// ============================================
const KBF_IMAGE_DISPLAY_SIDE = 1600;
const KBF_IMAGE_THUMB_SIDE = 480;

// The name of an original image without its extension, or null when the
// path is not an original image (or is itself a version)
function kbfImageVariantBase($file) {
    if (!is_string($file) || !preg_match('/^(.+)\.(jpe?g|png|webp|gif)$/i', $file, $m)) {
        return null;
    }
    if (preg_match('/\.(d|t)$/', $m[1])) {
        return null;
    }
    return $m[1];
}

// [display path, thumbnail path] in the format this server writes
// (WebP, or JPEG when GD cannot write WebP), or null for a non-original
function kbfImageVariantFiles($file) {
    $base = kbfImageVariantBase($file);
    if ($base === null) {
        return null;
    }
    $ext = kbfWebpWritable() ? 'webp' : 'jpg';
    return [$base . '.d.' . $ext, $base . '.t.' . $ext];
}

// Every version file an original may have (WebP and older JPEG ones)
function kbfImageAllVariantFiles($file) {
    $base = kbfImageVariantBase($file);
    if ($base === null) {
        return [];
    }
    return [$base . '.d.webp', $base . '.t.webp', $base . '.d.jpg', $base . '.t.jpg'];
}

// Make the two versions of an original image file. $force: make them again
// even if they exist. Returns true when both exist afterwards.
function kbfMakeImageVariants($file, $force = false) {
    $variants = kbfImageVariantFiles($file);
    if ($variants === null || !is_file($file) || !function_exists('imagecreatetruecolor')) {
        return false;
    }
    list($display, $thumb) = $variants;
    if (!$force && is_file($display) && is_file($thumb)) {
        return true;
    }
    try {
        $info = @getimagesize($file);
        if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > 50000000) {
            return false;
        }
        $source = kbfOpenImage($file, (string) ($info['mime'] ?? ''));
        if (!$source) {
            return false;
        }
        imagealphablending($source, false);
        imagesavealpha($source, true);
        $format = kbfWebpWritable() ? 'webp' : 'jpg';
        $ok = kbfWriteVariant($source, $display, KBF_IMAGE_DISPLAY_SIDE, $format, 82)
            && kbfWriteVariant($source, $thumb, KBF_IMAGE_THUMB_SIDE, $format, 78);
        imagedestroy($source);
        if (!$ok) {
            @unlink($display);
            @unlink($thumb);
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('kbfMakeImageVariants: ' . $e->getMessage());
        return false;
    }
}

// Scale $source down (never up) to $maxSide and save it in $format
function kbfWriteVariant($source, $target, $maxSide, $format, $quality) {
    $width = imagesx($source);
    $height = imagesy($source);
    $ratio = min(1, $maxSide / max($width, $height));
    $newWidth = max(1, (int) round($width * $ratio));
    $newHeight = max(1, (int) round($height * $ratio));
    $image = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    $ok = kbfWriteImage($image, $target, $format, $quality);
    imagedestroy($image);
    return $ok;
}

// Delete every version of an original image file (WebP and JPEG, if any)
function kbfDeleteImageVariants($file) {
    foreach (kbfImageAllVariantFiles($file) as $v) {
        if (is_file($v)) {
            @unlink($v);
        }
    }
}

// Decode with GD, fix JPEG orientation, scale down to KBF_IMAGE_MAX_SIDE and
// write a fresh file (no metadata is copied) at $targetBase plus the output
// format's extension (.webp, or .jpg/.png). Returns the path written, or null.
function kbfReencodeImage($sourcePath, $targetBase, $mimeType) {
    if (!function_exists('imagecreatetruecolor')) {
        error_log('kbfReencodeImage: the GD extension is missing');
        return null;
    }
    try {
        $source = kbfOpenImage($sourcePath, $mimeType);
        if (!$source) {
            return null;
        }

        if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            if ($orientation === 3) {
                $source = imagerotate($source, 180, 0);
            } elseif ($orientation === 6) {
                $source = imagerotate($source, -90, 0);
            } elseif ($orientation === 8) {
                $source = imagerotate($source, 90, 0);
            }
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, KBF_IMAGE_MAX_SIDE / max($width, $height));
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $image = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        $format = kbfImageOutputFormat($image, $mimeType !== 'image/jpeg');
        $targetPath = $targetBase . '.' . $format;
        $ok = kbfWriteImage($image, $targetPath, $format, 88);
        imagedestroy($image);
        if (!$ok) {
            @unlink($targetPath);
            return null;
        }
        return $targetPath;
    } catch (Throwable $e) {
        error_log('kbfReencodeImage: ' . $e->getMessage());
        return null;
    }
}

// An image sent as a data URI (data:image/png;base64,...): decoded into a
// temporary file and stored like an upload. Returns [url path or null, error or null].
function kbfStoreDataUriImage($dataUri, $destDir, $urlDir, $prefix, $maxBytes, $label = 'Image') {
    if (!is_string($dataUri) || !preg_match('#^data:image/(jpeg|jpg|png|gif|webp);base64,([A-Za-z0-9+/=\s]+)$#', $dataUri, $m)) {
        return [null, 'Invalid image format'];
    }
    // Base64 is about 4/3 of the size: refuse before decoding
    if (strlen($m[2]) > (int) ceil($maxBytes * 4 / 3) + 16) {
        return [null, $label . ' too large. Maximum ' . round($maxBytes / 1048576, 1) . ' MB'];
    }
    $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($bytes === false || $bytes === '') {
        return [null, 'Invalid image data'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'kbfimg');
    if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
        if ($tmp) {
            @unlink($tmp);
        }
        return [null, $label . ' upload failed'];
    }
    $result = kbfStoreImage($tmp, false, $destDir, $urlDir, $prefix, $maxBytes, $label);
    @unlink($tmp);
    return $result;
}

// Delete a stored image, only ever a plain file name inside $urlDir.
// $urlPath is the value saved in the database, e.g. /uploads/gallery/x.jpg
function kbfDeleteStoredImage($urlPath, $urlDir) {
    if (!is_string($urlPath) || $urlPath === '') {
        return;
    }
    $urlDir = '/' . trim($urlDir, '/') . '/';
    $path = '/' . ltrim($urlPath, '/');
    if (strpos($path, $urlDir) !== 0) {
        return;
    }
    $name = substr($path, strlen($urlDir));
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) || $name[0] === '.') {
        return;
    }
    $file = dirname(__DIR__) . $urlDir . $name;
    if (is_file($file)) {
        @unlink($file);
    }
    kbfDeleteImageVariants($file);
}
?>
