<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Login (401), then CSRF, then players.edit_details (403)
if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCSRFToken($token)) {
    sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
}

requirePermission('players.edit_details');

try {
    // Check if file was uploaded
    if (!isset($_FILES['profilePhoto']) || $_FILES['profilePhoto']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'No file uploaded';
        if (isset($_FILES['profilePhoto']['error'])) {
            switch ($_FILES['profilePhoto']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMsg = 'File too large';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMsg = 'File upload incomplete';
                    break;
            }
        }
        sendJSON(['success' => false, 'message' => $errorMsg], 400);
    }
    
    $file = $_FILES['profilePhoto'];
    
    // SECURITY: Validate file type by actual content, not just MIME
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        sendJSON(['success' => false, 'message' => 'Only JPG, PNG, and WEBP images are allowed'], 400);
    }
    
    // SECURITY: Additional validation - check image can be loaded
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        sendJSON(['success' => false, 'message' => 'Invalid image file'], 400);
    }
    
    // Validate file size (Site settings upload_mb_photo, 5 MB by default)
    $photoLimit = kbfUploadLimitBytes('upload_mb_photo', 5);
    if ($file['size'] > $photoLimit) {
        sendJSON(['success' => false, 'message' => 'Photo size must be less than ' . ($photoLimit / 1048576) . 'MB'], 400);
    }
    
    // SECURITY: Generate completely random filename (no user input)
    $extension = $imageInfo['mime'] === 'image/jpeg' ? 'jpg' : 
                ($imageInfo['mime'] === 'image/png' ? 'png' : 'webp');
    $filename = 'profile_' . bin2hex(random_bytes(16)) . '_' . time() . '.' . $extension;
    
    $uploadDir = __DIR__ . '/../uploads/profiles/';
    $uploadPath = $uploadDir . $filename;
    
    // SECURITY: Ensure directory exists with proper permissions
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            sendJSON(['success' => false, 'message' => 'Failed to create upload directory'], 500);
        }
    }
    
    // Upload file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        sendJSON(['success' => false, 'message' => 'Failed to upload photo'], 500);
    }
    
    // Resize image to 500x500 and fix EXIF orientation
    if (!resizeImage($uploadPath, 500, 500)) {
        unlink($uploadPath);
        sendJSON(['success' => false, 'message' => 'Failed to process image'], 500);
    }
    
    $photoPath = '/uploads/profiles/' . $filename;
    
    sendJSON([
        'success' => true,
        'message' => 'Photo uploaded successfully',
        'photoPath' => $photoPath
    ]);
    
} catch (Exception $e) {
    error_log('Photo upload error: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'Upload error occurred'], 500);
}

/**
 * Resize image to specified dimensions while maintaining aspect ratio
 * Also fixes EXIF orientation issues
 */
function resizeImage($filepath, $maxWidth, $maxHeight) {
    try {
        // Get image info
        $imageInfo = @getimagesize($filepath);
        if ($imageInfo === false) {
            return false;
        }
        
        list($width, $height, $type) = $imageInfo;
        
        // Load source image based on type
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = @imagecreatefromjpeg($filepath);
                break;
            case IMAGETYPE_PNG:
                $source = @imagecreatefrompng($filepath);
                break;
            case IMAGETYPE_WEBP:
                $source = @imagecreatefromwebp($filepath);
                break;
            default:
                return false;
        }
        
        if ($source === false) {
            return false;
        }
        
        // Fix EXIF orientation (this solves the rotation issue)
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($filepath);
            if ($exif && isset($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3:
                        $source = imagerotate($source, 180, 0);
                        break;
                    case 6:
                        $source = imagerotate($source, -90, 0);
                        $temp = $width;
                        $width = $height;
                        $height = $temp;
                        break;
                    case 8:
                        $source = imagerotate($source, 90, 0);
                        $temp = $width;
                        $width = $height;
                        $height = $temp;
                        break;
                }
            }
        }
        
        // Calculate new dimensions maintaining aspect ratio
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
        
        // Create new image
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        if ($newImage === false) {
            imagedestroy($source);
            return false;
        }
        
        // Preserve transparency for PNG
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
        }
        
        // Resize
        $success = imagecopyresampled($newImage, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        if (!$success) {
            imagedestroy($source);
            imagedestroy($newImage);
            return false;
        }
        
        // Save resized image
        switch ($type) {
            case IMAGETYPE_JPEG:
                $success = imagejpeg($newImage, $filepath, 90);
                break;
            case IMAGETYPE_PNG:
                $success = imagepng($newImage, $filepath, 9);
                break;
            case IMAGETYPE_WEBP:
                $success = imagewebp($newImage, $filepath, 90);
                break;
        }
        
        // Free memory
        imagedestroy($source);
        imagedestroy($newImage);
        
        return $success;
    } catch (Exception $e) {
        error_log('Image resize error: ' . $e->getMessage());
        return false;
    }
}
?>