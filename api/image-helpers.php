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

    $extension = KBF_IMAGE_TYPES[$mimeType];
    $filename = $prefix . bin2hex(random_bytes(16)) . '.' . $extension;
    $target = rtrim($destDir, '/\\') . '/' . $filename;

    if (!kbfReencodeImage($sourcePath, $target, $mimeType)) {
        @unlink($target);
        return [null, 'The image could not be processed. Please try a different image.'];
    }
    if ($isUploaded) {
        @unlink($sourcePath);
    }
    return [rtrim($urlDir, '/') . '/' . $filename, null];
}

// Decode with GD, fix JPEG orientation, scale down to KBF_IMAGE_MAX_SIDE and
// write a fresh file (no metadata is copied). Returns true on success.
function kbfReencodeImage($sourcePath, $targetPath, $mimeType) {
    if (!function_exists('imagecreatetruecolor')) {
        error_log('kbfReencodeImage: the GD extension is missing');
        return false;
    }
    try {
        switch ($mimeType) {
            case 'image/jpeg': $source = @imagecreatefromjpeg($sourcePath); break;
            case 'image/png': $source = @imagecreatefrompng($sourcePath); break;
            case 'image/webp': $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false; break;
            case 'image/gif': $source = @imagecreatefromgif($sourcePath); break;
            default: $source = false;
        }
        if (!$source) {
            return false;
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
        if ($mimeType === 'image/png' || $mimeType === 'image/webp' || $mimeType === 'image/gif') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        }
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        switch ($mimeType) {
            case 'image/jpeg': $ok = imagejpeg($image, $targetPath, 88); break;
            case 'image/png': $ok = imagepng($image, $targetPath, 8); break;
            case 'image/webp': $ok = function_exists('imagewebp') && imagewebp($image, $targetPath, 88); break;
            case 'image/gif': $ok = imagegif($image, $targetPath); break;
            default: $ok = false;
        }
        imagedestroy($image);
        return (bool) $ok && is_file($targetPath);
    } catch (Throwable $e) {
        error_log('kbfReencodeImage: ' . $e->getMessage());
        return false;
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
}
?>
