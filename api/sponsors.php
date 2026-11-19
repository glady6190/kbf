<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';

// ============================================
// SPONSORS API
//   GET                 public: active sponsors, queue order
//   GET ?all=1          content.sponsors: every sponsor, including hidden ones
//   POST action=create  content.sponsors: multipart (name, logo, websiteUrl, description, isActive)
//   POST action=update  content.sponsors: multipart (id + same fields; logo optional)
//   POST action=setActive content.sponsors: id, isActive (1 or 0); saved at once
//   PUT                 content.sponsors: JSON { "order": [sponsorId, ...] } full list in new order
//   DELETE ?id=         content.sponsors
// ============================================

const SPONSOR_LOGO_URL_DIR = '/assets/images/sponsors/';
// Logo size limit: Site settings upload_mb_sponsor (2 MB by default)
function sponsorLogoMaxBytes() {
    return kbfUploadLimitBytes('upload_mb_sponsor', 2);
}

function sponsorLogoTooBigMessage() {
    return 'Logo must be ' . (sponsorLogoMaxBytes() / 1048576) . ' MB or smaller';
}

$method = $_SERVER['REQUEST_METHOD'];

// Auth runs BEFORE CSRF (same order as api/matches.php) so an expired session
// returns a clear 401 instead of a misleading 403 "Invalid security token".
// Then the permission check: content.sponsors for every change.
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
    requirePermission('content.sponsors');
}

// ============================================
// HELPERS
// ============================================

// True when the error means the Sponsors table has not been created yet.
function sponsorsTableMissing(PDOException $e) {
    return $e->getCode() === '42S02' || strpos($e->getMessage(), '1146') !== false;
}

// Plain single-line text: trimmed, control characters and line breaks removed.
function cleanSponsorText($value) {
    $text = is_string($value) ? $value : '';
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', (string) $text);
    return trim((string) $text);
}

function sponsorTextLength($text) {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

// Returns a safe http/https URL, null for empty input, or false when invalid.
// Missing scheme gets https:// added. Any other scheme (javascript:, data:, ...) is rejected.
function normaliseSponsorUrl($value) {
    $url = trim(is_string($value) ? $value : '');
    if ($url === '') {
        return null;
    }
    if (strlen($url) > 255 || preg_match('/[\s\x00-\x1F\x7F<>"\'`]/', $url)) {
        return false;
    }

    if (preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $url, $m)) {
        // Has an explicit scheme: only http and https are allowed
        if (!in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return false;
        }
    } elseif (preg_match('#^[a-z][a-z0-9+.\-]*:(?!\d)#i', $url)) {
        // Scheme without // (javascript:, data:, mailto: ...) - never allowed
        return false;
    } else {
        $url = 'https://' . ltrim($url, '/');
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
        return false;
    }
    if (strpos($parts['host'], '.') === false) {
        return false;
    }
    return strlen($url) > 255 ? false : $url;
}

// Validate and store an uploaded logo. Same checks as api/upload-profile-photo.php:
// real type via finfo, must load via getimagesize, size limit, random file name.
// Returns the public path, or sends a JSON error.
function storeSponsorLogo(array $file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Logo upload failed';
        if (isset($file['error'])) {
            switch ($file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMsg = sponsorLogoTooBigMessage();
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMsg = 'Logo upload incomplete';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMsg = 'Please choose a logo image';
                    break;
            }
        }
        sendJSON(['success' => false, 'message' => $errorMsg], 400);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        sendJSON(['success' => false, 'message' => 'Invalid upload'], 400);
    }

    // Validate file size (Site settings upload_mb_sponsor)
    if ($file['size'] > sponsorLogoMaxBytes()) {
        sendJSON(['success' => false, 'message' => sponsorLogoTooBigMessage()], 400);
    }

    // SECURITY: Validate file type by actual content, not just MIME (no SVG)
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes, true)) {
        sendJSON(['success' => false, 'message' => 'Only JPG, PNG and WebP logos are allowed'], 400);
    }

    // SECURITY: Additional validation - check image can be loaded
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || !in_array($imageInfo['mime'], $allowedTypes, true)) {
        sendJSON(['success' => false, 'message' => 'Invalid image file'], 400);
    }

    // SECURITY: Generate completely random filename (no user input)
    $extension = $imageInfo['mime'] === 'image/jpeg' ? 'jpg' :
                ($imageInfo['mime'] === 'image/png' ? 'png' : 'webp');
    $filename = 'sponsor_' . bin2hex(random_bytes(16)) . '.' . $extension;

    $uploadDir = __DIR__ . '/..' . SPONSOR_LOGO_URL_DIR;
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            sendJSON(['success' => false, 'message' => 'Failed to create upload directory'], 500);
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        sendJSON(['success' => false, 'message' => 'Failed to save logo'], 500);
    }

    // Saved as WebP keeping transparency (JPEG, or PNG when transparent, if
    // the server cannot write WebP), with a display version and thumbnail
    require_once __DIR__ . '/image-helpers.php';
    $saved = kbfConvertStoredImage($uploadDir . $filename);
    kbfMakeImageVariants($saved);

    return SPONSOR_LOGO_URL_DIR . basename($saved);
}

// Delete a logo file, only ever inside the sponsors folder.
function deleteSponsorLogo($logoPath) {
    if (!is_string($logoPath) || strpos($logoPath, SPONSOR_LOGO_URL_DIR) !== 0) {
        return;
    }
    $basename = basename($logoPath);
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $basename) || $basename === '.' || $basename === '..') {
        return;
    }
    $fullPath = __DIR__ . '/..' . SPONSOR_LOGO_URL_DIR . $basename;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
    require_once __DIR__ . '/image-helpers.php';
    kbfDeleteImageVariants($fullPath);
}

function tableMissingResponse() {
    sendJSON(['success' => false, 'message' => 'The Sponsors table does not exist yet. Run database/sponsors.sql first.'], 500);
}

// ============================================
// GET - list sponsors
// ============================================
if ($method === 'GET') {
    $all = isset($_GET['all']) && $_GET['all'] === '1';
    if ($all) {
        requirePermission('content.sponsors');
    }

    try {
        if ($all) {
            $stmt = $pdo->prepare("
                SELECT SponsorId AS id, Name AS name, LogoPath AS logoPath,
                       WebsiteUrl AS websiteUrl, Description AS description,
                       DisplayOrder AS displayOrder, IsActive AS isActive
                FROM Sponsors
                ORDER BY DisplayOrder ASC, SponsorId ASC
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT SponsorId AS id, Name AS name, LogoPath AS logoPath,
                       WebsiteUrl AS websiteUrl, Description AS description
                FROM Sponsors
                WHERE IsActive = 1
                ORDER BY DisplayOrder ASC, SponsorId ASC
            ");
        }
        $stmt->execute();
        $sponsors = $stmt->fetchAll();

        foreach ($sponsors as &$s) {
            $s['id'] = (int) $s['id'];
            if ($all) {
                $s['displayOrder'] = (int) $s['displayOrder'];
                $s['isActive'] = (int) $s['isActive'] === 1;
            }
        }
        unset($s);

        sendJSON(['success' => true, 'sponsors' => $sponsors]);

    } catch (PDOException $e) {
        if (sponsorsTableMissing($e)) {
            // Table not created yet: behave as "no sponsors"
            sendJSON(['success' => true, 'sponsors' => [], 'tableMissing' => true]);
        }
        error_log('Database error in sponsors.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// POST - create or update (multipart, carries the logo file)
// ============================================
if ($method === 'POST') {
    // A request bigger than post_max_size arrives with empty $_POST and $_FILES
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        sendJSON(['success' => false, 'message' => sponsorLogoTooBigMessage()], 400);
    }

    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['create', 'update', 'setActive'], true)) {
        sendJSON(['success' => false, 'message' => 'Invalid action'], 400);
    }

    // Active switch in the list: only IsActive changes. Inactive sponsors
    // stay in the list but are not shown on the home page.
    if ($action === 'setActive') {
        $sponsorId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sponsorId || $sponsorId < 1) {
            sendJSON(['success' => false, 'message' => 'Invalid sponsor ID'], 400);
        }
        $flag = $_POST['isActive'] ?? '';
        if ($flag !== '1' && $flag !== '0') {
            sendJSON(['success' => false, 'message' => 'Invalid value'], 400);
        }
        $isActive = $flag === '1' ? 1 : 0;
        try {
            $stmt = $pdo->prepare("SELECT Name, IsActive FROM Sponsors WHERE SponsorId = ?");
            $stmt->execute([$sponsorId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                sendJSON(['success' => false, 'message' => 'Sponsor not found'], 404);
            }
            if ((int) $existing['IsActive'] !== $isActive) {
                $stmt = $pdo->prepare("UPDATE Sponsors SET IsActive = ? WHERE SponsorId = ?");
                $stmt->execute([$isActive, $sponsorId]);
                auditLog('sponsor_active_changed', 'sponsor', $sponsorId,
                    'Sponsor: ' . $existing['Name'] . ($isActive ? ' (shown on the home page)' : ' (hidden from the home page)'));
            }
            sendJSON([
                'success' => true,
                'isActive' => $isActive === 1,
                'message' => $isActive ? 'Shown on the home page' : 'Hidden from the home page',
            ]);
        } catch (PDOException $e) {
            if (sponsorsTableMissing($e)) {
                tableMissingResponse();
            }
            error_log('Database error in sponsors.php setActive: ' . $e->getMessage());
            sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
        }
    }

    $sponsorId = null;
    if ($action === 'update') {
        $sponsorId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sponsorId || $sponsorId < 1) {
            sendJSON(['success' => false, 'message' => 'Invalid sponsor ID'], 400);
        }
    }

    // ---- Validate fields ----
    $name = cleanSponsorText($_POST['name'] ?? '');
    if ($name === '' || sponsorTextLength($name) > 150) {
        sendJSON(['success' => false, 'message' => 'Name is required and must be 150 characters or fewer'], 400);
    }

    $description = cleanSponsorText($_POST['description'] ?? '');
    if (sponsorTextLength($description) > 160) {
        sendJSON(['success' => false, 'message' => 'Description must be 160 characters or fewer'], 400);
    }
    $description = $description === '' ? null : $description;

    $websiteUrl = normaliseSponsorUrl($_POST['websiteUrl'] ?? '');
    if ($websiteUrl === false) {
        sendJSON(['success' => false, 'message' => 'Website must be a valid http or https address'], 400);
    }

    $isActive = (isset($_POST['isActive']) && $_POST['isActive'] === '1') ? 1 : 0;

    $hasLogo = isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($action === 'create' && !$hasLogo) {
        sendJSON(['success' => false, 'message' => 'A logo is required for a new sponsor'], 400);
    }

    try {
        // For updates, load the existing row first (and its old logo)
        $oldLogoPath = null;
        if ($action === 'update') {
            $stmt = $pdo->prepare("SELECT LogoPath FROM Sponsors WHERE SponsorId = ?");
            $stmt->execute([$sponsorId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                sendJSON(['success' => false, 'message' => 'Sponsor not found'], 404);
            }
            $oldLogoPath = $existing['LogoPath'];
        }

        $newLogoPath = $hasLogo ? storeSponsorLogo($_FILES['logo']) : null;

        try {
            if ($action === 'create') {
                $stmt = $pdo->query("SELECT COALESCE(MAX(DisplayOrder), 0) + 1 AS nextOrder FROM Sponsors");
                $nextOrder = (int) $stmt->fetch()['nextOrder'];

                $stmt = $pdo->prepare("
                    INSERT INTO Sponsors (Name, LogoPath, WebsiteUrl, Description, DisplayOrder, IsActive)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $newLogoPath, $websiteUrl, $description, $nextOrder, $isActive]);
                $newSponsorId = (int) $pdo->lastInsertId();

                auditLog('sponsor_created', 'sponsor', $newSponsorId, 'Sponsor: ' . $name);

                sendJSON([
                    'success' => true,
                    'message' => 'Sponsor added',
                    'id' => $newSponsorId
                ]);
            }

            // Update: keep the old logo when no new one was sent
            $stmt = $pdo->prepare("
                UPDATE Sponsors
                SET Name = ?, LogoPath = ?, WebsiteUrl = ?, Description = ?, IsActive = ?
                WHERE SponsorId = ?
            ");
            $stmt->execute([
                $name,
                $newLogoPath ?? $oldLogoPath,
                $websiteUrl,
                $description,
                $isActive,
                $sponsorId
            ]);
        } catch (PDOException $e) {
            // Do not leave an orphaned new file behind
            if ($newLogoPath) {
                deleteSponsorLogo($newLogoPath);
            }
            throw $e;
        }

        // Logo replaced: remove the old file
        if ($newLogoPath && $oldLogoPath && $oldLogoPath !== $newLogoPath) {
            deleteSponsorLogo($oldLogoPath);
        }

        auditLog('sponsor_updated', 'sponsor', $sponsorId, 'Sponsor: ' . $name . ($isActive ? '' : ' (hidden)'));

        sendJSON(['success' => true, 'message' => 'Sponsor updated']);

    } catch (PDOException $e) {
        if (sponsorsTableMissing($e)) {
            tableMissingResponse();
        }
        error_log('Database error in sponsors.php POST: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// PUT - reorder (full list of sponsor ids in their new order)
// ============================================
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $order = is_array($data) && isset($data['order']) && is_array($data['order']) ? $data['order'] : null;
    if ($order === null || count($order) === 0) {
        sendJSON(['success' => false, 'message' => 'Order list required'], 400);
    }

    $ids = [];
    foreach ($order as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            sendJSON(['success' => false, 'message' => 'Invalid sponsor ID in order list'], 400);
        }
        $ids[] = $id;
    }
    if (count(array_unique($ids)) !== count($ids)) {
        sendJSON(['success' => false, 'message' => 'Duplicate sponsor ID in order list'], 400);
    }

    try {
        // The list must contain every sponsor exactly once
        $existingIds = array_map('intval', $pdo->query("SELECT SponsorId FROM Sponsors")->fetchAll(PDO::FETCH_COLUMN));
        $sortedNew = $ids;
        sort($sortedNew);
        sort($existingIds);
        if ($sortedNew !== $existingIds) {
            sendJSON(['success' => false, 'message' => 'The sponsor list has changed. Please reload and try again.'], 409);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE Sponsors SET DisplayOrder = ? WHERE SponsorId = ?");
        foreach ($ids as $index => $id) {
            $stmt->execute([$index + 1, $id]);
        }
        $pdo->commit();

        auditLog('sponsors_reordered', 'sponsor', null, 'Sponsor display order changed');

        sendJSON(['success' => true, 'message' => 'Order saved']);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (sponsorsTableMissing($e)) {
            tableMissingResponse();
        }
        error_log('Database error in sponsors.php PUT: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// DELETE - remove a sponsor and its logo file
// ============================================
if ($method === 'DELETE') {
    $sponsorId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$sponsorId || $sponsorId < 1) {
        sendJSON(['success' => false, 'message' => 'Invalid sponsor ID'], 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT Name, LogoPath FROM Sponsors WHERE SponsorId = ?");
        $stmt->execute([$sponsorId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            sendJSON(['success' => false, 'message' => 'Sponsor not found'], 404);
        }

        $stmt = $pdo->prepare("DELETE FROM Sponsors WHERE SponsorId = ?");
        $stmt->execute([$sponsorId]);

        deleteSponsorLogo($existing['LogoPath']);

        auditLog('sponsor_deleted', 'sponsor', $sponsorId, 'Sponsor: ' . $existing['Name']);

        sendJSON(['success' => true, 'message' => 'Sponsor deleted']);

    } catch (PDOException $e) {
        if (sponsorsTableMissing($e)) {
            tableMissingResponse();
        }
        error_log('Database error in sponsors.php DELETE: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
?>
