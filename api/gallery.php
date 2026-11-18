<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';

// ============================================
// PUBLIC: GET ALL TOURNAMENTS WITH PHOTOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['tournamentId'])) {
    try {
        // Get all completed tournaments that have photos
        $query = "
            SELECT 
                t.TournamentId,
                t.Name,
                t.TournamentDate,
                t.Venue,
                t.Location,
                " . organiserNameSql('t', 'c') . " as ClubName,
                " . organiserNameSql('t', 'c') . " as OrganiserName,
                COUNT(g.PhotoId) as PhotoCount,
                MIN(g.FilePath) as ThumbnailPath
            FROM Tournaments t
            LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
            LEFT JOIN GalleryPhotos g ON t.TournamentId = g.TournamentId
            WHERE t.Status = 'Completed'
            GROUP BY t.TournamentId
            HAVING PhotoCount > 0
            ORDER BY t.TournamentDate DESC
        ";
        
        $stmt = $pdo->query($query);
        $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJSON([
            'success' => true,
            'tournaments' => $tournaments
        ]);
        
    } catch(PDOException $e) {
        error_log('Database error in gallery.php: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// PUBLIC: GET PHOTOS FOR SPECIFIC TOURNAMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['tournamentId'])) {
    try {
        $tournamentId = (int)$_GET['tournamentId'];
        
        // Get tournament details
        $tournamentQuery = "
            SELECT 
                t.TournamentId,
                t.Name,
                t.TournamentDate,
                t.Venue,
                t.Location,
                " . organiserNameSql('t', 'c') . " as ClubName,
                " . organiserNameSql('t', 'c') . " as OrganiserName
            FROM Tournaments t
            LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
            WHERE t.TournamentId = ? AND t.Status = 'Completed'
        ";
        
        $stmt = $pdo->prepare($tournamentQuery);
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) {
            sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
        }
        
        // Get photos
        $photosQuery = "
            SELECT 
                PhotoId,
                FilePath,
                UploadedDate,
                DisplayOrder
            FROM GalleryPhotos
            WHERE TournamentId = ?
            ORDER BY DisplayOrder ASC, UploadedDate DESC
        ";
        
        $stmt = $pdo->prepare($photosQuery);
        $stmt->execute([$tournamentId]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJSON([
            'success' => true,
            'tournament' => $tournament,
            'photos' => $photos
        ]);
        
    } catch(PDOException $e) {
        error_log('Database error in gallery.php: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// WRITE ACCESS (uploads and deletes)
// Login (401), then CSRF, then permission (403).
// content.all_news_gallery: any tournament.
// content.own_news_gallery: only tournaments the user created.
// ============================================
function requireGalleryWriteAccess() {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
    if (!hasAnyPermission(['content.all_news_gallery', 'content.own_news_gallery'])) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
}

// Ownership is read from the database row ($tournament has CreatedByAdminId)
function requireGalleryTournamentAccess($tournament) {
    if (hasPermission('content.all_news_gallery')) {
        return;
    }
    if (!isOwnTournamentRow($tournament)) {
        sendJSON(['success' => false, 'message' => 'You can only manage photos for your own tournaments'], 403);
    }
}

// ============================================
// UPLOAD PHOTOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $pdo; // CRITICAL: Access database connection

    requireGalleryWriteAccess();
    
    try {
        // Get tournament ID from POST data
        $tournamentId = (int)($_POST['tournamentId'] ?? 0);
        
        if (!$tournamentId) {
            sendJSON(['success' => false, 'message' => 'Tournament ID is required'], 400);
        }
        
        // Verify access to the tournament and that it is completed
        $tournament = loadTournamentForAccess($tournamentId);

        if (!$tournament) {
            sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
        }

        requireGalleryTournamentAccess($tournament);
        
        // Check if tournament is completed
        if ($tournament['Status'] !== 'Completed') {
            sendJSON(['success' => false, 'message' => 'Can only upload photos for completed tournaments'], 400);
        }
        
        // Check current photo count
        $countQuery = "SELECT COUNT(*) as count FROM GalleryPhotos WHERE TournamentId = ?";
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute([$tournamentId]);
        $currentCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($currentCount >= 10) {
            sendJSON(['success' => false, 'message' => 'Maximum 10 photos per tournament'], 400);
        }
        
        // Check if photos were uploaded
        if (!isset($_FILES['photos']) || empty($_FILES['photos']['name'][0])) {
            sendJSON(['success' => false, 'message' => 'No photos uploaded'], 400);
        }
        
        // Create directory for this tournament
        $uploadDir = __DIR__ . '/../uploads/gallery/' . $tournamentId . '/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                sendJSON(['success' => false, 'message' => 'Failed to create upload directory'], 500);
            }
        }
        
        $uploadedPhotos = [];
        $filesArray = $_FILES['photos'];
        $fileCount = count($filesArray['name']);
        
        // Check if total would exceed 10
        if ($currentCount + $fileCount > 10) {
            sendJSON(['success' => false, 'message' => 'Cannot upload ' . $fileCount . ' photos. Maximum 10 photos per tournament. You have ' . $currentCount . ' already.'], 400);
        }
        
        // Get session user ID
       $sessionUserId = $_SESSION['user_id'] ?? ($_SESSION['user']['user_id'] ?? null);
        
        if (!$sessionUserId) {
            error_log('gallery.php: user ID missing from session');
            sendJSON(['success' => false, 'message' => 'Session error: User ID not found'], 500);
        }
        
        for ($i = 0; $i < $fileCount; $i++) {
            // Skip if error
            if ($filesArray['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $fileName = $filesArray['name'][$i];
            $fileTmpName = $filesArray['tmp_name'][$i];
            $fileSize = $filesArray['size'][$i];
            
            // Validate file size (Site settings upload_mb_gallery, 15 MB by default)
            $galleryLimit = kbfUploadLimitBytes('upload_mb_gallery', 15);
            if ($fileSize > $galleryLimit) {
                sendJSON(['success' => false, 'message' => 'File ' . $fileName . ' exceeds ' . ($galleryLimit / 1048576) . 'MB limit'], 400);
            }
            
            // Validate file type
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                sendJSON(['success' => false, 'message' => 'File ' . $fileName . ' has invalid format. Only JPG, PNG, WEBP allowed'], 400);
            }
            
            // Verify it's actually an image
            $imageInfo = getimagesize($fileTmpName);
            if ($imageInfo === false) {
                sendJSON(['success' => false, 'message' => 'File ' . $fileName . ' is not a valid image'], 400);
            }
            
            // Generate unique filename
            $newFileName = uniqid('photo_', true) . '.' . $fileExtension;
            $uploadPath = $uploadDir . $newFileName;
            
            // Move uploaded file
            if (!move_uploaded_file($fileTmpName, $uploadPath)) {
                sendJSON(['success' => false, 'message' => 'Failed to upload ' . $fileName], 500);
            }
            
            // Insert into database
            $filePath = '/uploads/gallery/' . $tournamentId . '/' . $newFileName;
            $insertQuery = "
                INSERT INTO GalleryPhotos (TournamentId, FilePath, UploadedBy, DisplayOrder)
                VALUES (?, ?, ?, ?)
            ";
            
            $stmt = $pdo->prepare($insertQuery);
            $stmt->execute([
                $tournamentId,
                $filePath,
                $sessionUserId,
                $currentCount + $i
            ]);
            
            $uploadedPhotos[] = [
                'photoId' => $pdo->lastInsertId(),
                'filePath' => $filePath
            ];
        }
        
        sendJSON([
            'success' => true,
            'message' => count($uploadedPhotos) . ' photo(s) uploaded successfully',
            'photos' => $uploadedPhotos
        ]);
        
    } catch(PDOException $e) {
        error_log('Database error in gallery.php POST: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// DELETE PHOTO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    global $pdo; // CRITICAL: Access database connection

    requireGalleryWriteAccess();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $photoId = (int)($input['photoId'] ?? 0);
        
        if (!$photoId) {
            sendJSON(['success' => false, 'message' => 'Photo ID is required'], 400);
        }
        
        // Get photo details and verify ownership
        $query = "
            SELECT g.PhotoId, g.FilePath, g.TournamentId, t.CreatedByAdminId
            FROM GalleryPhotos g
            INNER JOIN Tournaments t ON g.TournamentId = t.TournamentId
            WHERE g.PhotoId = ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$photo) {
            sendJSON(['success' => false, 'message' => 'Photo not found'], 404);
        }
        
        // Verify access to the photo's tournament (ownership from the database)
        requireGalleryTournamentAccess($photo);
        
        // Delete file from server
        $filePath = __DIR__ . '/../' . $photo['FilePath'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Delete from database
        $deleteQuery = "DELETE FROM GalleryPhotos WHERE PhotoId = ?";
        $stmt = $pdo->prepare($deleteQuery);
        $stmt->execute([$photoId]);
        
        sendJSON([
            'success' => true,
            'message' => 'Photo deleted successfully'
        ]);
        
    } catch(PDOException $e) {
        error_log('Database error in gallery.php DELETE: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
?>