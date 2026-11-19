<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/image-helpers.php';

const ANNOUNCEMENT_IMAGE_URL_DIR = '/uploads/announcements';

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
    // Hidden or unpublished posts: only staff with a content permission.
    // Everyone else gets published, visible posts only.
    $contentStaff = isLoggedIn() && hasAnyPermission(['content.all_news_gallery', 'content.own_news_gallery']);
    $publicOnlySql = $contentStaff ? "" : " AND a.IsPublished = 1 AND a.IsVisible = 1";

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
    WHERE t.OrganizingClubId = ? AND a.IsVisible = 1" . $publicOnlySql . "
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
                WHERE a.AnnouncementId = ?" . $publicOnlySql . "
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
            
            // SECURITY: Enforce reasonable limits (PERF-06: at most 50 a
            // page; older posts are reached with offset)
            if ($limit === false || $limit < 1) {
                $limit = 10;
            }
            $limit = min(50, (int) $limit);
            if ($offset === false || $offset < 0) {
                $offset = 0;
            }
            $offset = min(1000000, (int) $offset);

            // Optional filters (News page tabs and search, home page):
            //   type        news (not promotions) | promotions |
            //               tournament (not promotions, about a tournament)
            //   tournament  one tournament's posts
            //   q           search in title, tournament name and text
            $filterSql = '';
            $filterParams = [];
            $promotionSql = "a.Title LIKE '%player promotion:%'";
            $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
            if ($type === 'news') {
                $filterSql .= " AND NOT ($promotionSql)";
            } elseif ($type === 'promotions') {
                $filterSql .= " AND $promotionSql";
            } elseif ($type === 'tournament') {
                $filterSql .= " AND NOT ($promotionSql) AND a.TournamentId > 0";
            } elseif ($type !== '' && $type !== 'all') {
                sendJSON(['success' => false, 'message' => 'Invalid type'], 400);
            }
            if (isset($_GET['tournament']) && $_GET['tournament'] !== '') {
                $tournamentFilter = filter_var($_GET['tournament'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$tournamentFilter) {
                    sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
                }
                $filterSql .= " AND a.TournamentId = ?";
                $filterParams[] = (int) $tournamentFilter;
            }
            $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
            if ($q !== '') {
                $q = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
                $filterSql .= " AND (a.Title LIKE ? OR t.Name LIKE ? OR a.Content LIKE ?)";
                array_push($filterParams, $like, $like, $like);
            }

            // Get total count (with the filters)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM Announcements a
                LEFT JOIN Tournaments t ON a.TournamentId = t.TournamentId
                WHERE a.IsPublished = 1 AND a.IsVisible = 1" . $filterSql);
            $stmt->execute($filterParams);
            $total = $stmt->fetch()['total'];

            // Get announcements: newest first (published date, else created
            // date). Content is a short plain-text excerpt in lists (HTML
            // tags removed, entities kept); the full text comes with ?id=
            $stmt = $pdo->prepare("
                SELECT a.AnnouncementId, a.Title, LEFT(a.Content, 4000) AS Content, a.FeaturedImage,
                       a.CreatedDate, a.PublishedDate, a.IsVisible,
                       " . $authorColumn . "
                       a.TournamentId,
                       t.Name as TournamentName,
                       t.TournamentDate
                FROM Announcements a
                " . $authorJoin . "
                LEFT JOIN Tournaments t ON a.TournamentId = t.TournamentId
                WHERE a.IsPublished = 1 AND a.IsVisible = 1" . $filterSql . "
                ORDER BY COALESCE(a.PublishedDate, a.CreatedDate) DESC, a.AnnouncementId DESC
                LIMIT ? OFFSET ?
            ");
            $i = 1;
            foreach ($filterParams as $p) {
                $stmt->bindValue($i++, $p);
            }
            $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($i, $offset, PDO::PARAM_INT);
            $stmt->execute();
            // No under-18 player's photo as a post image for visitors
            // (home page news cards and promotions strip, News page)
            $announcements = kbfPublicPostImages($pdo, $stmt->fetchAll());
            foreach ($announcements as &$post) {
                $stripped = (string) preg_replace(['/<[^>]*>/', '/<[^>]*$/'], [' ', ''], (string) $post['Content']);
                $plain = trim(preg_replace('/\s+/u', ' ', $stripped) ?? preg_replace('/[ \t\r\n]+/', ' ', $stripped));
                if ((function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain)) > 400) {
                    $plain = function_exists('mb_substr') ? mb_substr($plain, 0, 400, 'UTF-8') : substr($plain, 0, 400);
                    // No half entity at the end
                    $plain = preg_replace('/&[#A-Za-z0-9]*$/', '', $plain) . '…';
                }
                $post['Content'] = $plain;
                $post['Excerpt'] = $plain;
            }
            unset($post);

            $response = [
                'success' => true,
                'announcements' => $announcements,
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
                'hasMore' => $offset + count($announcements) < (int) $total
            ];
            // Tab counts for the News page (first page only)
            if ($offset === 0) {
                $counts = $pdo->query("
                    SELECT COUNT(*) AS AllPosts,
                           SUM(CASE WHEN $promotionSql THEN 1 ELSE 0 END) AS Promotions,
                           SUM(CASE WHEN NOT ($promotionSql) AND a.TournamentId > 0 THEN 1 ELSE 0 END) AS TournamentPosts
                    FROM Announcements a
                    WHERE a.IsPublished = 1 AND a.IsVisible = 1
                ")->fetch();
                $response['counts'] = [
                    'all' => (int) $counts['AllPosts'],
                    'promotions' => (int) $counts['Promotions'],
                    'tournament' => (int) $counts['TournamentPosts'],
                ];
            }
            if (!$showAuthor) {
                kbfPublicCache(60);
            }
            sendJSON($response);
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
        foreach (['title', 'content', 'tournamentId'] as $field) {
            if (isset($_POST[$field]) && !is_string($_POST[$field])) {
                sendJSON(['success' => false, 'message' => 'Invalid value for ' . $field], 400);
            }
        }
        // Titles never hold < or > (defence in depth against markup)
        $title = isset($_POST['title']) ? trim(str_replace(['<', '>'], '', $_POST['title'])) : null;
        $content = isset($_POST['content']) ? trim($_POST['content']) : null;
        $tournamentId = isset($_POST['tournamentId']) ? filter_var($_POST['tournamentId'], FILTER_VALIDATE_INT) : null;
        
        if (!$title || !$content) {
            sendJSON(['success' => false, 'message' => 'Title and content required'], 400);
        }
        
        // SECURITY: Validate input lengths
        if (kbfTextLength($title) < 3 || kbfTextLength($title) > 200) {
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

        // A repeat of the same one-time request id (double tap, retry) gets
        // the first answer back; no second post is created
        require_once __DIR__ . '/request-ids.php';
        kbfRequestIdBegin($pdo, 'news_post');

        // SECURITY: Handle image upload with validation
        $imagePath = null;
        if (isset($_FILES['featuredImage']) && is_array($_FILES['featuredImage'])
            && !is_array($_FILES['featuredImage']['error'] ?? null)
            && ($_FILES['featuredImage']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['featuredImage'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $tooBig = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
                sendJSON(['success' => false, 'message' => $tooBig ? 'Image too large' : 'Image upload failed'], 400);
            }
            // Same checks as tournament posters, then re-encoded (no EXIF)
            list($imagePath, $imageError) = kbfStoreImage($file['tmp_name'], true, $uploadsDir,
                ANNOUNCEMENT_IMAGE_URL_DIR, 'post_', kbfUploadLimitBytes('upload_mb_news', 5));
            if ($imageError !== null) {
                sendJSON(['success' => false, 'message' => $imageError], 400);
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
        $newPostId = (int) $pdo->lastInsertId();

        auditLog('post_created', 'post', $newPostId,
            'Post "' . mb_substr($title, 0, 80) . '"' . ($tournamentId ? ' (tournament ' . (int) $tournamentId . ')' : ''));

        sendJSON([
            'success' => true,
            'message' => 'Post published successfully',
            'announcementId' => $newPostId
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

    if (!is_array($data) || !isset($data['postId'])) {
        sendJSON(['success' => false, 'message' => 'Post ID required'], 400);
    }
    foreach (['title', 'content', 'featuredImage'] as $field) {
        if (isset($data[$field]) && !is_string($data[$field])) {
            sendJSON(['success' => false, 'message' => 'Invalid value for ' . $field], 400);
        }
    }
    if (is_array($data['postId'])) {
        sendJSON(['success' => false, 'message' => 'Invalid post ID'], 400);
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
        $oldImage = null;
        $newImage = null;

        // Update title if provided (never < or >)
if (isset($data['title'])) {
    $title = trim(str_replace(['<', '>'], '', $data['title']));
    if (kbfTextLength($title) < 3 || kbfTextLength($title) > 200) {
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
        // A new image (data URI): the same checks as tournament posters, then
        // re-encoded (no EXIF). The old file is deleted after the update.
        if (isset($data['featuredImage']) && strpos($data['featuredImage'], 'data:') === 0) {
            list($newImage, $imageError) = kbfStoreDataUriImage($data['featuredImage'], $uploadsDir,
                ANNOUNCEMENT_IMAGE_URL_DIR, 'post_', kbfUploadLimitBytes('upload_mb_news', 5));
            if ($imageError !== null) {
                sendJSON(['success' => false, 'message' => $imageError], 400);
            }
            $stmt = $pdo->prepare("SELECT FeaturedImage FROM Announcements WHERE AnnouncementId = ?");
            $stmt->execute([$postId]);
            $oldImage = $stmt->fetchColumn();
            $updates[] = "FeaturedImage = ?";
            $params[] = $newImage;
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

        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($newImage !== null) {
                kbfDeleteStoredImage($newImage, ANNOUNCEMENT_IMAGE_URL_DIR);
            }
            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            if ($newImage !== null) {
                kbfDeleteStoredImage($newImage, ANNOUNCEMENT_IMAGE_URL_DIR);
            }
            sendJSON(['success' => false, 'message' => 'Post not found or no changes made'], 404);
        }

        // The replaced image file goes
        if ($newImage !== null && is_string($oldImage) && $oldImage !== '' && $oldImage !== $newImage) {
            kbfDeleteStoredImage($oldImage, ANNOUNCEMENT_IMAGE_URL_DIR);
        }

        // Activity log: a visibility-only change is shown/hidden, anything else an edit
        $changed = [];
        if (isset($data['title'])) { $changed[] = 'title'; }
        if (isset($data['content'])) { $changed[] = 'content'; }
        if ($newImage !== null) { $changed[] = 'image'; }
        if (empty($changed) && isset($isVisible)) {
            auditLog($isVisible ? 'post_shown' : 'post_hidden', 'post', $postId, 'Post ' . (int) $postId);
        } else {
            $detail = 'Post ' . (int) $postId . ': changed ' . implode(', ', $changed);
            if (isset($isVisible)) {
                $detail .= $isVisible ? '; shown' : '; hidden';
            }
            auditLog('post_edited', 'post', $postId, $detail);
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

        $stmt = $pdo->prepare("SELECT FeaturedImage FROM Announcements WHERE AnnouncementId = ?");
        $stmt->execute([$announcementId]);
        $oldImage = $stmt->fetchColumn();

        // SECURITY: Soft delete - the post is hidden and its image removed
        $stmt = $pdo->prepare("UPDATE Announcements SET IsVisible = 0, FeaturedImage = NULL WHERE AnnouncementId = ?");
        $stmt->execute([$announcementId]);

        if ($stmt->rowCount() === 0) {
            sendJSON(['success' => false, 'message' => 'Announcement not found'], 404);
        }

        // Delete the image file (it is no longer online)
        if (is_string($oldImage) && $oldImage !== '') {
            kbfDeleteStoredImage($oldImage, ANNOUNCEMENT_IMAGE_URL_DIR);
        }

        auditLog('post_deleted', 'post', $announcementId, 'Post ' . (int) $announcementId . ' deleted (hidden, image removed)');

        sendJSON(['success' => true, 'message' => 'Announcement deleted successfully']);
    } catch(PDOException $e) {
        error_log('Announcement deletion error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
?>