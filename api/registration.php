<?php
require_once __DIR__ . '/security-init.php';

require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/auth/email-config.php';



// Load reCAPTCHA configuration
$recaptchaConfig = require_once 'recaptcha-config.php';
define('RECAPTCHA_SECRET_KEY', $recaptchaConfig['secret_key']);

/**
 * Verify reCAPTCHA response with Google
 */
function verifyRecaptcha($recaptchaResponse, $userIP) {
    if (empty($recaptchaResponse)) {
        return ['success' => false, 'message' => 'reCAPTCHA response is missing'];
    }
    
    $verifyURL = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => $recaptchaResponse,
        'remoteip' => $userIP
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($verifyURL, false, $context);
    
    if ($result === FALSE) {
        error_log('reCAPTCHA verification request failed');
        return ['success' => false, 'message' => 'Unable to verify reCAPTCHA. Please try again.'];
    }
    
    $resultJson = json_decode($result, true);
    
    if (!isset($resultJson['success']) || !$resultJson['success']) {
        $errorCodes = isset($resultJson['error-codes']) ? implode(', ', $resultJson['error-codes']) : 'unknown';
        error_log('reCAPTCHA verification failed: ' . $errorCodes);
        return ['success' => false, 'message' => 'reCAPTCHA verification failed. Please try again.'];
    }
    
    return ['success' => true];
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Registration closed (Site settings) or maintenance mode: no new registrations
if (kbfSettingInt('maintenance_mode', 0, 0, 1) === 1) {
    sendJSON(['success' => false, 'message' => (string) kbfSetting('maintenance_message', 'We are making some improvements. Please check back shortly.')], 503);
}
if (kbfSettingInt('registration_open', 1, 0, 1) === 0) {
    sendJSON(['success' => false, 'message' => (string) kbfSetting('registration_closed_message', 'Registration is closed for now. Please check back soon.')], 403);
}

// Get user IP address
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

try {
    // SECURITY: Verify reCAPTCHA FIRST before processing anything else
    $recaptchaResponse = $_POST['recaptcha_response'] ?? '';
    
    $recaptchaResult = verifyRecaptcha($recaptchaResponse, $ipAddress);
    if (!$recaptchaResult['success']) {
        sendJSON([
            'success' => false, 
            'message' => 'Please complete the reCAPTCHA verification.'
        ], 400);
    }
    
    // Rate limiting for public endpoint (prevent spam)
// First check: Global limit (1000/hour total)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM PlayerRegistrations 
    WHERE SubmittedDate > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$stmt->execute();
$result = $stmt->fetch();
if ($result['count'] >= 1000) {
    sendJSON(['success' => false, 'message' => 'Too many registration attempts. Please try again later.'], 429);
}

// Second check: Per-IP limit (Site settings registration_limit_per_ip_hour, 5 by default)
$perIpLimit = kbfSettingInt('registration_limit_per_ip_hour', 5, 1, 100);
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM PlayerRegistrations
    WHERE SubmittedDate > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    AND SubmittedIpAddress = ?
");
$stmt->execute([$ipAddress]);
$result = $stmt->fetch();
if ($result['count'] >= $perIpLimit) {
    sendJSON(['success' => false, 'message' => 'Too many registration attempts from your location. Please try again in 1 hour.'], 429);
}
    
    // The terms box must be ticked (checked here as well as in the browser)
    $termsTicked = isset($_POST['termsAccepted']) && in_array(strtolower(trim((string) $_POST['termsAccepted'])), ['on', '1', 'true', 'yes'], true);
    if (!$termsTicked) {
        sendJSON(['success' => false, 'message' => 'Please agree to the terms and conditions to register.'], 400);
    }

    // The version agreed to always comes from Site settings, never from the browser
    $termsVersion = substr(trim((string) kbfSetting('terms_version', '2026-11')), 0, 20);
    if ($termsVersion === '') {
        $termsVersion = '2026-11';
    }

    // Validate required fields
    $requiredFields =['firstName', 'lastName', 'email', 'phoneNumber', 'location', 'categoryId'];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            sendJSON(['success' => false, 'message' => "Field '$field' is required"], 400);
        }
    }
    
    // Validate and sanitize inputs
    $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        sendJSON(['success' => false, 'message' => 'Invalid email format'], 400);
    }
    
    $firstName = sanitizeInput($_POST['firstName']);
    $lastName = sanitizeInput($_POST['lastName']);
    $phoneNumber = sanitizeInput($_POST['phoneNumber']);
    $location = sanitizeInput($_POST['location']);
    $postCode = isset($_POST['postCode']) ? sanitizeInput($_POST['postCode']) : null;
    
    // Validate year of birth
    $yearOfBirth = null;
    if (isset($_POST['yearOfBirth']) && !empty($_POST['yearOfBirth'])) {
        $yearOfBirth = filter_var($_POST['yearOfBirth'], FILTER_VALIDATE_INT);
        // Youngest allowed age from Site settings (min_player_age)
        $latestYear = kbfLatestBirthYear();
        if (!$yearOfBirth || $yearOfBirth < 1940 || $yearOfBirth > $latestYear) {
            sendJSON(['success' => false, 'message' => 'Invalid year of birth. Must be between 1940 and ' . $latestYear], 400);
        }
    }
    
    // Validate category
    $categoryId = filter_var($_POST['categoryId'], FILTER_VALIDATE_INT);
    if (!$categoryId || !categoryExists($pdo, $categoryId)) {
        sendJSON(['success' => false, 'message' => 'Invalid category'], 400);
    }
    
    // Validate name lengths
   
if (strlen($firstName) < 2 || strlen($lastName) < 2) {
    sendJSON(['success' => false, 'message' => 'First name and surname must be at least 2 characters'], 400);
}

if (strlen($firstName) > 50 || strlen($lastName) > 50) {
    sendJSON(['success' => false, 'message' => 'Names must be less than 50 characters'], 400);
}

// Validate name format (only letters, spaces, hyphens, apostrophes)
if (!preg_match("/^[A-Za-z\s\-']+$/", $firstName) || !preg_match("/^[A-Za-z\s\-']+$/", $lastName)) {
    sendJSON(['success' => false, 'message' => 'Names can only contain letters, spaces, hyphens, and apostrophes'], 400);
}

// Check for suspicious patterns (single character repeated)
if (preg_match("/^(.)\1+$/", $firstName) || preg_match("/^(.)\1+$/", $lastName)) {
    sendJSON(['success' => false, 'message' => 'Please enter a valid name'], 400);
}
    
  
// Get full international phone number
$phoneNumber = isset($_POST['fullPhoneNumber']) && !empty($_POST['fullPhoneNumber']) 
    ? sanitizeInput($_POST['fullPhoneNumber']) 
    : sanitizeInput($_POST['phoneNumber']);

// Remove any spaces, dashes, parentheses for validation
$cleanPhoneNumber = preg_replace('/[\s\-\(\)]/', '', $phoneNumber);

// Validate phone number starts with +
if (!preg_match('/^\+/', $cleanPhoneNumber)) {
    sendJSON(['success' => false, 'message' => 'Invalid phone number format. Please include country code.'], 400);
}

// Fix common issue: Remove any zeros immediately after country code
// Example: +440777... becomes +44777...
$cleanPhoneNumber = preg_replace('/^(\+\d{1,4})0+/', '$1', $cleanPhoneNumber);

// Validate phone number format (international format with + and digits only)
if (!preg_match('/^\+[\d]+$/', $cleanPhoneNumber)) {
    sendJSON(['success' => false, 'message' => 'Invalid phone number format. Please use international format.'], 400);
}

// Validate phone number length (minimum 8 digits, maximum 15 digits after +)
$phoneDigits = str_replace('+', '', $cleanPhoneNumber);
if (strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {
    sendJSON(['success' => false, 'message' => 'Phone number must be between 8 and 15 digits'], 400);
}

// Use the cleaned phone number
$phoneNumber = $cleanPhoneNumber;
    
    // Check for duplicate registration - email
    $stmt = $pdo->prepare("
        SELECT UserId FROM Users 
        WHERE Email = ? AND IsActive = 1
    ");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendJSON(['success' => false, 'message' => 'A player with this email address is already registered'], 400);
    }
    
    $stmt = $pdo->prepare("
        SELECT RegistrationId FROM PlayerRegistrations 
        WHERE Email = ? AND Status = 'Pending'
    ");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendJSON(['success' => false, 'message' => 'A registration with this email is already pending approval'], 400);
    }
    
    // Additional duplicate check: FirstName + LastName + YearOfBirth + PhoneNumber
    if ($yearOfBirth !== null) {
        $stmt = $pdo->prepare("
            SELECT UserId FROM Users 
            WHERE FirstName = ? AND LastName = ? AND YearOfBirth = ? AND PhoneNumber = ? AND IsActive = 1
        ");
        $stmt->execute([$firstName, $lastName, $yearOfBirth, $phoneNumber]);
        if ($stmt->fetch()) {
            sendJSON(['success' => false, 'message' => 'A player with matching details already exists in the system'], 400);
        }
        
        $stmt = $pdo->prepare("
            SELECT RegistrationId FROM PlayerRegistrations 
            WHERE FirstName = ? AND LastName = ? AND YearOfBirth = ? AND PhoneNumber = ? AND Status = 'Pending'
        ");
        $stmt->execute([$firstName, $lastName, $yearOfBirth, $phoneNumber]);
        if ($stmt->fetch()) {
            sendJSON(['success' => false, 'message' => 'A registration with matching details is already pending approval'], 400);
        }
    }
    
    // Handle profile photo upload
    $profilePhotoPath = null;
    if (isset($_FILES['profilePhoto']) && $_FILES['profilePhoto']['error'] === UPLOAD_ERR_OK) {
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
        $filename = 'reg_' . bin2hex(random_bytes(16)) . '_' . time() . '.' . $extension;
        
        $uploadDir = __DIR__ . '/../uploads/registrations/';
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
        
        // Resize image and fix EXIF orientation
        if (!resizeImage($uploadPath, 500, 500)) {
            unlink($uploadPath);
            sendJSON(['success' => false, 'message' => 'Failed to process image'], 500);
        }
        
        // Display version and thumbnail (a problem never stops the registration)
        require_once __DIR__ . '/image-helpers.php';
        kbfMakeImageVariants($uploadPath);

        $profilePhotoPath = '/uploads/registrations/' . $filename;
    }
    
   // Start transaction for Player ID generation
   // Start transaction for Player ID generation
$pdo->beginTransaction();

try {
    // Lock BOTH tables to prevent race conditions during ID generation
    $pdo->query("SELECT RegistrationId FROM PlayerRegistrations ORDER BY RegistrationId DESC LIMIT 1 FOR UPDATE");
    $pdo->query("SELECT UserId FROM Users ORDER BY UserId DESC LIMIT 1 FOR UPDATE");
    
    // New KBF ID: the highest number is kept in Settings (locked row), so
    // no table is scanned (api/config-helpers.php kbfNextKbfId)
    $playerId = kbfNextKbfId($pdo);
    
    // Insert into PlayerRegistrations

    $stmt = $pdo->prepare("
        INSERT INTO PlayerRegistrations 
        (PlayerId, FirstName, LastName, Email, PhoneNumber, Location, PostCode, 
         YearOfBirth, CategoryId, ProfilePhoto, SubmittedIpAddress, Status, SubmittedDate,
         TermsVersion, TermsAcceptedDate)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, NOW())
    ");
    
    $stmt->execute([
        $playerId,
        $firstName,
        $lastName,
        $email,
        $phoneNumber,
        $location,
        $postCode,
        $yearOfBirth,
        $categoryId,
        $profilePhotoPath,
        $ipAddress,
        $termsVersion
    ]);
    
    $registrationId = $pdo->lastInsertId();
    
    // Commit transaction (automatically releases all locks)
    $pdo->commit();

    // Notifications after the commit. notify() logs its own errors and never
    // fails the registration. The KBF/membership ID is deliberately NOT
    // included in the applicant's email.
    try {
        $gradeStmt = $pdo->prepare("SELECT Name FROM Categories WHERE CategoryId = ?");
        $gradeStmt->execute([$categoryId]);
        $gradeName = (string) $gradeStmt->fetchColumn();

        // sanitizeInput() stored HTML-escaped text; emails escape values themselves
        $applicant = [
            'email' => $email,
            'firstName' => html_entity_decode((string) $firstName, ENT_QUOTES, 'UTF-8'),
            'lastName' => html_entity_decode((string) $lastName, ENT_QUOTES, 'UTF-8'),
        ];
        notify('registration_received', [
            'person' => $applicant,
            'relatedType' => 'registration',
            'relatedId' => (int) $registrationId,
        ]);
        notify('registration_waiting', [
            'values' => [
                'first_name' => html_entity_decode((string) $firstName, ENT_QUOTES, 'UTF-8'),
                'full_name' => html_entity_decode(trim($firstName . ' ' . $lastName), ENT_QUOTES, 'UTF-8'),
                'grade' => $gradeName,
                'location' => html_entity_decode((string) $location, ENT_QUOTES, 'UTF-8'),
            ],
            'relatedType' => 'registration',
            'relatedId' => (int) $registrationId,
        ]);
    } catch (\Throwable $e) {
        error_log('Registration notification error: ' . $e->getMessage());
    }

    sendJSON([
        'success' => true,
        'message' => 'Registration submitted successfully',
        'registrationId' => $registrationId
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    
    // Delete uploaded photo if transaction fails
    if (isset($profilePhotoPath) && file_exists(__DIR__ . '/..' . $profilePhotoPath)) {
        unlink(__DIR__ . '/..' . $profilePhotoPath);
    }
    
    throw $e;
}
    
} catch (PDOException $e) {
    error_log('Registration error: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'An error occurred. Please try again later.'], 500);
} catch (Exception $e) {
    error_log('Registration error: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'An error occurred. Please try again later.'], 500);
}

/**
 * Resize image to specified dimensions while maintaining aspect ratio
 * Also fixes EXIF orientation issues
 */
function resizeImage($filepath, $maxWidth, $maxHeight) {
    try {
        $imageInfo = @getimagesize($filepath);
        if ($imageInfo === false) {
            return false;
        }
        
        list($width, $height, $type) = $imageInfo;
        
        // Load source image
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
        
        // Fix EXIF orientation
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
        
        // Calculate new dimensions
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
        
        // Create new image (this strips EXIF data automatically!)
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
        imagecopyresampled($newImage, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Save WITHOUT EXIF data (by recreating the image)
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
        
        imagedestroy($source);
        imagedestroy($newImage);
        
        return $success;
    } catch (Exception $e) {
        error_log('Image resize error: ' . $e->getMessage());
        return false;
    }
}
?>