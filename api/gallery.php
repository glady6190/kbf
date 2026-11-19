<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/image-helpers.php';

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

        // Same for every visitor: cached briefly when no one is signed in
        kbfPublicCache(60);
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

        kbfPublicCache(60);
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
        
        // Check if photos were uploaded (photos[] only)
        if (!isset($_FILES['photos']) || !is_array($_FILES['photos']['name'] ?? null)
            || !is_array($_FILES['photos']['tmp_name'] ?? null) || !is_array($_FILES['photos']['error'] ?? null)
            || empty($_FILES['photos']['name'][0])) {
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
        
        // Same checks as tournament posters (finfo, getimagesize, extension
        // from the detected type, random name, Site settings size limit), then
        // re-encoded with GD so location and camera data are removed.
        // Messages give the photo's number, never the uploaded file name.
        $galleryLimit = kbfUploadLimitBytes('upload_mb_gallery', 15);
        $urlDir = '/uploads/gallery/' . $tournamentId;
        for ($i = 0; $i < $fileCount; $i++) {
            // Skip if error
            if (($filesArray['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $fileTmpName = $filesArray['tmp_name'][$i];
            if (!is_string($fileTmpName)) {
                continue;
            }

            list($filePath, $imageError) = kbfStoreImage($fileTmpName, true, $uploadDir, $urlDir, 'photo_', $galleryLimit, 'Photo ' . ($i + 1));
            if ($imageError !== null) {
                if ($uploadedPhotos) {
                    auditLog('gallery_photos_uploaded', 'tournament', $tournamentId,
                        count($uploadedPhotos) . ' photo(s) uploaded for tournament ' . (int) $tournamentId . ' before an error');
                }
                $prefix = strpos($imageError, 'Photo ') === 0 ? '' : 'Photo ' . ($i + 1) . ': ';
                sendJSON(['success' => false, 'message' => $prefix . $imageError, 'photos' => $uploadedPhotos], 400);
            }

            // Insert into database
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
        
        if ($uploadedPhotos) {
            auditLog('gallery_photos_uploaded', 'tournament', $tournamentId,
                count($uploadedPhotos) . ' photo(s) uploaded for tournament ' . (int) $tournamentId);
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
        if (!is_array($input) || is_array($input['photoId'] ?? null)) {
            sendJSON(['success' => false, 'message' => 'Photo ID is required'], 400);
        }
        $photoId = (int) filter_var($input['photoId'] ?? 0, FILTER_VALIDATE_INT);
        
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
        
        // Delete from database
        $deleteQuery = "DELETE FROM GalleryPhotos WHERE PhotoId = ?";
        $stmt = $pdo->prepare($deleteQuery);
        $stmt->execute([$photoId]);

        // Delete the file, only ever inside this tournament's gallery folder
        kbfDeleteStoredImage((string) $photo['FilePath'], '/uploads/gallery/' . (int) $photo['TournamentId']);

        auditLog('gallery_photo_deleted', 'tournament', (int) $photo['TournamentId'], 'Photo ' . (int) $photoId . ' deleted');

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