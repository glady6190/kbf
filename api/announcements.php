<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';

// CREATE uploads directory if it doesn't exist
$uploadsDir = dirname(__DIR__) . '/uploads/announcements';
if (!file_exists($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

// Writes: login (401), then CSRF, then permission (403).
// content.all_news_gallery: any post.
// content.own_news_gallery: only posts they created, linked to their own
// tournaments that are Approved or Completed.
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
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

// True when the current user may post about this tournament id with
// content.own_news_gallery: they created it and it is Approved or Completed.
function canPostAboutOwnTournament($tournamentId) {
    if (!$tournamentId) {
        return false;
    }
    $tournament = loadTournamentForAccess($tournamentId);
    return $tournament
        && isOwnTournamentRow($tournament)
        && in_array($tournament['Status'], ['Approved', 'Completed'], true);
}

// Enforce access to an existing post (read from the database). Ends the
// request with 404 or 403 when not allowed.
function requirePostAccess($postId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT AnnouncementId, CreatedByAdminId, TournamentId FROM Announcements WHERE AnnouncementId = ?");
    $stmt->execute([(int) $postId]);
    $post = $stmt->fetch();
    if (!$post) {
        sendJSON(['success' => false, 'message' => 'Post not found'], 404);
    }
    if (hasPermission('content.all_news_gallery')) {
        return $post;
    }
    $isOwnPost = (int) $post['CreatedByAdminId'] === (int) $_SESSION['user_id'];
    if (!$isOwnPost || !canPostAboutOwnTournament((int) $post['TournamentId'])) {
        sendJSON(['success' => false, 'message' => 'You can only change your own posts about your own approved tournaments'], 403);
    }
    return $post;
}

// ============================================
// SINGLE POST PAGE EXTRAS (post.html, GET ?id=)
//   tournament       the linked tournament when it is public (Approved or
//                    Completed): name, date, venue, town, registration link
//                    and closing date
//   related          up to 3 other visible posts about the same tournament
//   previous / next  the neighbouring visible posts by date (id and title)
//   promotionPlayer  for "Player Promotion:" posts, the player named by the
//                    "Player ID:" in the text: name, KBF ID, photo (never for
//                    an under-18 player), profile id (active players only).
//                    A removed player is "Former member" with nothing else,
//                    and for visitors the post's own title, text and image
//                    are masked too.
// Nothing private is sent: no year of birth, no author.
// ============================================
function postPageExtras($pdo, array $post) {
    $extras = ['tournament' => null, 'related' => [], 'previous' => null, 'next' => null, 'promotionPlayer' => null];
    $postId = (int) $post['AnnouncementId'];
    $tournamentId = (int) ($post['TournamentId'] ?? 0);

    // Linked tournament (public statuses only) and other posts about it
    if ($tournamentId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT TournamentId, Name, TournamentDate, Venue, Location, Status,
                       RegistrationUrl, RegistrationCloseDate
                FROM Tournaments
                WHERE TournamentId = ? AND Status IN ('Approved', 'Completed')
            ");
            $stmt->execute([$tournamentId]);
            $t = $stmt->fetch();
            if ($t) {
                $extras['tournament'] = [
                    'id' => (int) $t['TournamentId'],
                    'name' => (string) $t['Name'],
                    'date' => $t['TournamentDate'] ? substr((string) $t['TournamentDate'], 0, 10) : null,
                    'venue' => (string) ($t['Venue'] ?? ''),
                    'town' => (string) ($t['Location'] ?? ''),
                    'status' => (string) $t['Status'],
                    'registrationUrl' => (string) ($t['RegistrationUrl'] ?? ''),
                    'registrationCloseDate' => $t['RegistrationCloseDate'] ? substr((string) $t['RegistrationCloseDate'], 0, 10) : null,
                ];
            }
        } catch (PDOException $e) {
            error_log('announcements.php post tournament: ' . $e->getMessage());
        }

        try {
            $stmt = $pdo->prepare("
                SELECT AnnouncementId, Title, FeaturedImage, PublishedDate, CreatedDate
                FROM Announcements
                WHERE TournamentId = ? AND AnnouncementId <> ? AND IsPublished = 1 AND IsVisible = 1
                ORDER BY COALESCE(PublishedDate, CreatedDate) DESC, AnnouncementId DESC
                LIMIT 3
            ");
            $stmt->execute([$tournamentId, $postId]);
            $extras['related'] = kbfPublicPostImages($pdo, $stmt->fetchAll());
        } catch (PDOException $e) {
            error_log('announcements.php related posts: ' . $e->getMessage());
        }
    }

    // Previous (older) and next (newer) visible posts by date
    try {
        $date = $post['PublishedDate'] ?: $post['CreatedDate'];
        if ($date) {
            $dateSql = "COALESCE(PublishedDate, CreatedDate)";
            $stmt = $pdo->prepare("
                SELECT AnnouncementId, Title FROM Announcements
                WHERE IsPublished = 1 AND IsVisible = 1 AND AnnouncementId <> ?
                  AND ($dateSql < ? OR ($dateSql = ? AND AnnouncementId < ?))
                ORDER BY $dateSql DESC, AnnouncementId DESC
                LIMIT 1
            ");
            $stmt->execute([$postId, $date, $date, $postId]);
            $extras['previous'] = $stmt->fetch() ?: null;

            $stmt = $pdo->prepare("
                SELECT AnnouncementId, Title FROM Announcements
                WHERE IsPublished = 1 AND IsVisible = 1 AND AnnouncementId <> ?
                  AND ($dateSql > ? OR ($dateSql = ? AND AnnouncementId > ?))
                ORDER BY $dateSql ASC, AnnouncementId ASC
                LIMIT 1
            ");
            $stmt->execute([$postId, $date, $date, $postId]);
            $extras['next'] = $stmt->fetch() ?: null;
        }
    } catch (PDOException $e) {
        error_log('announcements.php previous/next: ' . $e->getMessage());
    }

    // Promotion posts: the player from "Player ID: ..." in the text
    if (stripos((string) $post['Title'], 'Player Promotion:') !== false
        && preg_match('/Player ID:\s*([A-Za-z0-9\-]+)/i', (string) $post['Content'], $m)) {
        $player = null;
        try {
            $stmt = $pdo->prepare("
                SELECT UserId, FirstName, LastName, PlayerId, ProfilePhoto, YearOfBirth, IsActive,
                       COALESCE(IsRemoved, 0) AS IsRemoved
                FROM Users WHERE PlayerId = ? LIMIT 1
            ");
            $stmt->execute([$m[1]]);
            $player = $stmt->fetch();
        } catch (PDOException $e) {
            // IsRemoved not added yet (database/people.sql): read without it
            try {
                $stmt = $pdo->prepare("
                    SELECT UserId, FirstName, LastName, PlayerId, ProfilePhoto, YearOfBirth, IsActive, 0 AS IsRemoved
                    FROM Users WHERE PlayerId = ? LIMIT 1
                ");
                $stmt->execute([$m[1]]);
                $player = $stmt->fetch();
            } catch (PDOException $e2) {
                error_log('announcements.php promotion player: ' . $e2->getMessage());
            }
        }

        if ($player && (int) $player['IsRemoved'] === 1) {
            $extras['promotionPlayer'] = ['removed' => true, 'name' => 'Former member'];
            if (!kbfViewerIsStaff()) {
                $post['Title'] = 'Player Promotion: Former member';
                $post['Content'] = '';
                $post['FeaturedImage'] = null;
            }
        } elseif ($player) {
            $extras['promotionPlayer'] = [
                'removed' => false,
                'name' => trim($player['FirstName'] . ' ' . $player['LastName']),
                'kbfId' => (string) $player['PlayerId'],
                // Initials only for an under-18 player, for every viewer
                'photo' => (!empty($player['ProfilePhoto']) && !kbfIsUnder18($player['YearOfBirth'] ?? null))
                    ? (string) $player['ProfilePhoto'] : null,
                'profileId' => (int) $player['IsActive'] === 1 ? (int) $player['UserId'] : null,
            ];
        }
    }

    return [$post, $extras];
}

// GET - Fetch announcements
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // PRIVACY: who wrote a post (name or user id) is only sent to signed-in
    // staff. Visitors get the post without any author or creator details.
    $showAuthor = isLoggedIn() && isStaff();
    $authorColumn = $showAuthor ? "CONCAT(u.FirstName, ' ', u.LastName) as AuthorName," : "";
    $authorJoin = $showAuthor ? "LEFT JOIN Users u ON a.CreatedByAdminId = u.UserId" : "";

    try {
        // My posts: every post the current user created, any status.
        // Needs content.own_news_gallery or content.all_news_gallery.
        if (isset($_GET['mine'])) {
            if (!isLoggedIn()) {
                sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
            }
            if (!hasAnyPermission(['content.own_news_gallery', 'content.all_news_gallery'])) {
                sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
            $stmt = $pdo->prepare("
                SELECT a.AnnouncementId, a.Title, a.Content, a.FeaturedImage, a.CreatedDate,
                       a.PublishedDate, a.IsPublished, a.IsVisible, a.TournamentId, a.CreatedByAdminId,
                       t.Name as TournamentName, t.TournamentDate, t.Status as TournamentStatus
                FROM Announcements a
                LEFT JOIN Tournaments t ON a.TournamentId = t.TournamentId
                WHERE a.CreatedByAdminId = ?
                ORDER BY a.CreatedDate DESC, a.AnnouncementId DESC
            ");
            $stmt->execute([(int) $_SESSION['user_id']]);
            sendJSON(['success' => true, 'announcements' => $stmt->fetchAll()]);
        }

        // Filter by clubId if provided (for Club Admin Posts page)
        if (isset($_GET['clubId'])) {
            $clubId = filter_var($_GET['clubId'], FILTER_VALIDATE_INT);
            if (!$clubId) {
                sendJSON(['success' => false, 'message' => 'Invalid club ID'], 400);
            }
            
            $stmt = $pdo->prepare("
    SELECT a.AnnouncementId, a.Title, a.Content, a.FeaturedImage, a.CreatedDate,
           a.IsPublished, a.IsVisible,
           t.Name as TournamentName, t.TournamentId
    FROM Announcements a
    JOIN Tournaments t ON a.TournamentId = t.TournamentId
    WHERE t.OrganizingClubId = ? AND a.IsVisible = 1
    ORDER BY a.CreatedDate DESC
");
            $stmt->execute([$clubId]);
            // No under-18 player's photo as a post image for visitors
            $announcements = kbfPublicPostImages($pdo, $stmt->fetchAll());

            sendJSON(['success' => true, 'announcements' => $announcements]);
        }
        
        // Get single announcement by ID
        elseif (isset($_GET['id'])) {
            // SECURITY: Validate announcement ID
            $announcementId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            if (!$announcementId) {
                sendJSON(['success' => false, 'message' => 'Invalid announcement ID'], 400);
            }
            
            // Get single announcement
            $stmt = $pdo->prepare("
                SELECT a.*,
                       " . $authorColumn . "
                       t.Name as TournamentName,
                       t.TournamentDate,
                       t.TournamentId,
                       t.OrganizingClubId
                FROM Announcements a
                " . $authorJoin . "
                LEFT JOIN Tournaments t ON a.TournamentId = t.TournamentId
                WHERE a.AnnouncementId = ?
            ");
            $stmt->execute([$announcementId]);
            $announcement = $stmt->fetch();

            if ($announcement) {
                if (!$showAuthor) {
                    unset($announcement['CreatedByAdminId']);
                }
                // No under-18 player's photo as a post image for visitors
                $announcement = kbfPublicPostImages($pdo, [$announcement])[0];
                // Tournament box, related posts, previous/next, promotion card
                list($announcement, $extras) = postPageExtras($pdo, $announcement);
                sendJSON(['success' => true, 'announcement' => $announcement] + $extras);
            } else {
                sendJSON(['success' => false, 'message' => 'Announcement not found'], 404);
            }
        } 
        
        // Get all announcements - PUBLIC ACCESS
        else {
            // SECURITY: Validate and sanitize pagination params
            $limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT) : 10;
            $offset = isset($_GET['offset']) ? filter_var($_GET['offset'], FILTER_VALIDATE_INT) : 0;
            
            // SECURITY: Enforce reasonable limits (up to 500, so the News
            // page can load every published post in one request)
            if ($limit === false || $limit < 1 || $limit > 500) {
                $limit = 10;
            }
            if ($offset === false || $offset < 0) {
                $offset = 0;
            }
            
            // Get total count
            $stmt = $pdo->query("
                SELECT COUNT(*) as total 
                FROM Announcements 
                WHERE IsPublished = 1 AND IsVisible = 1
            ");
            $total = $stmt->fetch()['total'];
            
            // Get announcements
            $stmt = $pdo->prepare("
                SELECT a.AnnouncementId, a.Title, a.Content, a.FeaturedImage,
                       a.CreatedDate, a.PublishedDate, a.IsVisible,
                       " . $authorColumn . "
                       a.TournamentId,
                       t.Name as TournamentName,
                       t.TournamentDate
                FROM Announcements a
                " . $authorJoin . "
                LEFT JOIN Tournaments t ON a.TournamentId = t.TournamentId
                WHERE a.IsPublished = 1 AND a.IsVisible = 1
                ORDER BY a.PublishedDate DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            // No under-18 player's photo as a post image for visitors
            // (home page news cards and promotions strip, News page)
            $announcements = kbfPublicPostImages($pdo, $stmt->fetchAll());
            
            sendJSON([
                'success' => true, 
                'announcements' => $announcements,
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset
            ]);
        }
    } catch(PDOException $e) {
        error_log('Database error in announcements.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// POST - Create announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    try {
        // SECURITY: Validate and sanitize inputs
        $title = isset($_POST['title']) ? trim($_POST['title']) : null;
$content = isset($_POST['content']) ? trim($_POST['content']) : null;
        $tournamentId = isset($_POST['tournamentId']) ? filter_var($_POST['tournamentId'], FILTER_VALIDATE_INT) : null;
        
        if (!$title || !$content) {
            sendJSON(['success' => false, 'message' => 'Title and content required'], 400);
        }
        
        // SECURITY: Validate input lengths
        if (strlen($title) < 3 || strlen($title) > 200) {
            sendJSON(['success' => false, 'message' => 'Title must be between 3 and 200 characters'], 400);
        }
        
        if (strlen($content) < 10 || strlen($content) > 10000) {
            sendJSON(['success' => false, 'message' => 'Content must be between 10 and 10000 characters'], 400);
        }
        
        // SECURITY: Validate tournament ID if provided
        if ($tournamentId !== null && $tournamentId !== false && $tournamentId > 0) {
            $stmt = $pdo->prepare("SELECT TournamentId FROM Tournaments WHERE TournamentId = ?");
            $stmt->execute([$tournamentId]);
            if (!$stmt->fetch()) {
                sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
            }
        } else {
            $tournamentId = null;
        }

        // content.own_news_gallery only: must be linked to their own Approved or Completed tournament
        if (!hasPermission('content.all_news_gallery') && !canPostAboutOwnTournament($tournamentId)) {
            sendJSON(['success' => false, 'message' => 'Posts must be linked to one of your own approved tournaments'], 403);
        }
                
        // SECURITY: Handle image upload with validation
        $imagePath = null;
        if (isset($_FILES['featuredImage']) && $_FILES['featuredImage']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['featuredImage'];
            
            // SECURITY: Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                sendJSON(['success' => false, 'message' => 'Invalid image type. Only JPG, PNG, GIF, WEBP allowed'], 400);
            }
            
            // SECURITY: Validate file size (Site settings upload_mb_news, 5 MB by default)
            $newsLimit = kbfUploadLimitBytes('upload_mb_news', 5);
            if ($file['size'] > $newsLimit) {
                sendJSON(['success' => false, 'message' => 'Image too large. Maximum ' . ($newsLimit / 1048576) . 'MB'], 400);
            }
            
            // SECURITY: Generate unique filename to prevent path traversal
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $extension = 'jpg';
            }
            $filename = uniqid('post_', true) . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $uploadPath = $uploadsDir . '/' . $filename;
            
            // SECURITY: Ensure filename doesn't contain path traversal
            $filename = basename($filename);
            $uploadPath = realpath($uploadsDir) . '/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $imagePath = '/uploads/announcements/' . $filename;
            } else {
                sendJSON(['success' => false, 'message' => 'Failed to upload image'], 500);
            }
        }
        
        // Insert announcement
        $stmt = $pdo->prepare("
            INSERT INTO Announcements 
            (Title, Content, FeaturedImage, TournamentId, CreatedByAdminId, CreatedDate, PublishedDate, IsPublished, IsVisible) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 1)
        ");
        
        $stmt->execute([
            $title,
            $content,
            $imagePath,
            $tournamentId,
            $_SESSION['user_id']
        ]);
        
        sendJSON([
            'success' => true, 
            'message' => 'Post published successfully',
            'announcementId' => $pdo->lastInsertId()
        ]);
        
    } catch(PDOException $e) {
        error_log('Announcement creation error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// PUT - Update announcement (edit, hide, show)
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!isset($data['postId'])) {
        sendJSON(['success' => false, 'message' => 'Post ID required'], 400);
    }
    
    // SECURITY: Validate post ID
    $postId = filter_var($data['postId'], FILTER_VALIDATE_INT);
    if (!$postId) {
        sendJSON(['success' => false, 'message' => 'Invalid post ID'], 400);
    }

    try {
        requirePostAccess($postId);

        // Build update query
        $updates = [];
        $params = [];
        
        // Update title if provided
        // Update title if provided
if (isset($data['title'])) {
    $title = trim($data['title']);
    if (strlen($title) < 3 || strlen($title) > 200) {
        sendJSON(['success' => false, 'message' => 'Title must be between 3 and 200 characters'], 400);
    }
    $updates[] = "Title = ?";
    $params[] = $title;
}

// Update content if provided
if (isset($data['content'])) {
    $content = trim($data['content']);
    if (strlen($content) < 10 || strlen($content) > 10000) {
        sendJSON(['success' => false, 'message' => 'Content must be between 10 and 10000 characters'], 400);
    }
    $updates[] = "Content = ?";
    $params[] = $content;
}
        
        // Update featured image if provided
        if (isset($data['featuredImage']) && !empty($data['featuredImage'])) {
            // Handle base64 image
            $imageData = $data['featuredImage'];
            
            // Check if it's a base64 image
            if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
                $type = strtolower($type[1]); // jpg, png, gif
                
                if (!in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    sendJSON(['success' => false, 'message' => 'Invalid image type'], 400);
                }
                
                $imageData = base64_decode($imageData);
                
                if ($imageData === false) {
                    sendJSON(['success' => false, 'message' => 'Base64 decode failed'], 400);
                }
                
                // Validate file size (Site settings upload_mb_news, 5 MB by default)
                $newsLimit = kbfUploadLimitBytes('upload_mb_news', 5);
                if (strlen($imageData) > $newsLimit) {
                    sendJSON(['success' => false, 'message' => 'Image too large. Maximum ' . ($newsLimit / 1048576) . 'MB'], 400);
                }
                
                // Generate unique filename
                $filename = uniqid('post_', true) . '_' . bin2hex(random_bytes(8)) . '.' . $type;
                $uploadPath = $uploadsDir . '/' . $filename;
                
                if (file_put_contents($uploadPath, $imageData)) {
                    $imagePath = '/uploads/announcements/' . $filename;
                    $updates[] = "FeaturedImage = ?";
                    $params[] = $imagePath;
                }
            }
        }
        
        // Update visibility if provided (hide/show; public pages only list IsVisible = 1)
        if (array_key_exists('isVisible', $data)) {
            $isVisible = $data['isVisible'];
            if ($isVisible === true || $isVisible === 1 || $isVisible === '1') {
                $isVisible = 1;
            } elseif ($isVisible === false || $isVisible === 0 || $isVisible === '0') {
                $isVisible = 0;
            } else {
                sendJSON(['success' => false, 'message' => 'Invalid visibility value'], 400);
            }
            $updates[] = "IsVisible = ?";
            $params[] = $isVisible;
        }

        if (empty($updates)) {
            sendJSON(['success' => false, 'message' => 'No fields to update'], 400);
        }

        // Add postId to params
        $params[] = $postId;
        
        $query = "UPDATE Announcements SET " . implode(", ", $updates) . " WHERE AnnouncementId = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() === 0) {
            sendJSON(['success' => false, 'message' => 'Post not found or no changes made'], 404);
        }
        
        sendJSON(['success' => true, 'message' => 'Post updated successfully']);
        
    } catch(PDOException $e) {
        error_log('Announcement update error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// DELETE - Delete announcement
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    
    if (!isset($_GET['id'])) {
        sendJSON(['success' => false, 'message' => 'Announcement ID required'], 400);
    }
    
    // SECURITY: Validate announcement ID
    $announcementId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if (!$announcementId) {
        sendJSON(['success' => false, 'message' => 'Invalid announcement ID'], 400);
    }
    
    try {
        requirePostAccess($announcementId);

        // SECURITY: Soft delete - just hide it
        $stmt = $pdo->prepare("UPDATE Announcements SET IsVisible = 0 WHERE AnnouncementId = ?");
        $stmt->execute([$announcementId]);
        
        if ($stmt->rowCount() === 0) {
            sendJSON(['success' => false, 'message' => 'Announcement not found'], 404);
        }
        
        sendJSON(['success' => true, 'message' => 'Announcement deleted successfully']);
    } catch(PDOException $e) {
        error_log('Announcement deletion error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
?>