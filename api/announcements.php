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
// NEWS EDITOR (admin/post-editor.html)
// New posts are stored as HTML (ContentFormat 'html'), cleaned here with an
// allowlist: p, br, strong, b, em, i, h2, h3, ul, ol, li, blockquote, a
// (http, https or mailto links only) and img only when its src is one of
// this site's own uploaded news images. Web addresses in plain text become
// links. Older posts stay 'text' and show exactly as before.
// PostStatus: 'draft' and 'scheduled' posts are saved with IsPublished = 0,
// so no public query ever shows them; 'published' posts have
// IsPublished = 1. Hidden = IsVisible 0 (as before).
// ============================================

const KBF_POST_MAX_PHOTOS = 12;
const KBF_POST_INLINE_SRC = '#^/uploads/announcements/[A-Za-z0-9_-]+(\.(d|t))?\.(webp|jpe?g|png|gif)$#';

// Plain text of stored post HTML (entities decoded, spaces collapsed)
function kbfPostPlainText($html) {
    $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h2|/h3|/li|/blockquote)\b[^>]*>#i', ' $0', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

// Escaped text with web addresses turned into links
function kbfPostLinkify($text) {
    $out = '';
    $pattern = '~\b((?:https?://|www\.)[^\s<>"\']+)~iu';
    $parts = preg_split($pattern, (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $out .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            continue;
        }
        // Trailing punctuation is not part of the address
        $trail = '';
        if (preg_match('/[.,;:!?)\]]+$/u', $part, $m)) {
            $trail = $m[0];
            $part = substr($part, 0, -strlen($trail));
        }
        $href = stripos($part, 'www.') === 0 ? 'https://' . $part : $part;
        if (filter_var($href, FILTER_VALIDATE_URL) === false) {
            $out .= htmlspecialchars($part . $trail, ENT_QUOTES, 'UTF-8');
            continue;
        }
        $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer" target="_blank">'
            . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</a>' . htmlspecialchars($trail, ENT_QUOTES, 'UTF-8');
    }
    return $out;
}

// A safe link address (http, https or mailto), or null
function kbfPostSafeHref($href) {
    $href = trim((string) $href);
    if ($href === '' || preg_match('/[\x00-\x1F\x7F]/', $href)) {
        return null;
    }
    if (preg_match('#^mailto:[^\s<>"\']+@[^\s<>"\']+$#i', $href)) {
        return $href;
    }
    if (preg_match('#^https?://#i', $href) && filter_var($href, FILTER_VALIDATE_URL) !== false) {
        return $href;
    }
    return null;
}

// An inline picture address: only this site's uploaded news images that exist
function kbfPostSafeImageSrc($src) {
    $src = trim((string) $src);
    // A full address on this site becomes a site path
    if (preg_match('#^https?://[^/]+(/uploads/announcements/.*)$#i', $src, $m)) {
        $src = $m[1];
    }
    if (!preg_match(KBF_POST_INLINE_SRC, $src)) {
        return null;
    }
    return is_file(dirname(__DIR__) . $src) ? $src : null;
}

// Clean one DOM node's children into allowlisted HTML
function kbfPostCleanChildren(DOMNode $node, $inLink = false) {
    static $keep = ['p', 'br', 'strong', 'b', 'em', 'i', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'a', 'img'];
    static $drop = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math',
        'head', 'title', 'textarea', 'select', 'button', 'form', 'input', 'video', 'audio', 'canvas', 'meta', 'link'];
    static $blocks = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'table', 'section', 'article'];
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            $out .= $inLink ? htmlspecialchars($child->nodeValue, ENT_QUOTES, 'UTF-8') : kbfPostLinkify($child->nodeValue);
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        $tag = strtolower($child->nodeName);
        if (in_array($tag, $drop, true)) {
            continue;
        }
        // Close relatives become allowed tags
        if ($tag === 'h1') {
            $tag = 'h2';
        } elseif (in_array($tag, ['h4', 'h5', 'h6'], true)) {
            $tag = 'h3';
        } elseif ($tag === 'div') {
            // A div holding blocks is unwrapped; a plain one is a paragraph
            $hasBlock = false;
            foreach ($child->childNodes as $grandchild) {
                if ($grandchild->nodeType === XML_ELEMENT_NODE && in_array(strtolower($grandchild->nodeName), $blocks, true)) {
                    $hasBlock = true;
                    break;
                }
            }
            $tag = $hasBlock ? 'div' : 'p';
        }
        if (!in_array($tag, $keep, true)) {
            $out .= kbfPostCleanChildren($child, $inLink);
            continue;
        }
        if ($tag === 'br') {
            $out .= '<br>';
            continue;
        }
        if ($tag === 'img') {
            $src = kbfPostSafeImageSrc($child->getAttribute('src'));
            if ($src !== null) {
                $alt = kbfCleanText(mb_substr((string) $child->getAttribute('alt'), 0, 200, 'UTF-8'));
                $out .= '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">';
            }
            continue;
        }
        if ($tag === 'a') {
            $href = $inLink ? null : kbfPostSafeHref($child->getAttribute('href'));
            $inner = kbfPostCleanChildren($child, true);
            if ($href === null) {
                $out .= $inner;
            } elseif (stripos($href, 'mailto:') === 0) {
                $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $inner . '</a>';
            } else {
                $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer" target="_blank">' . $inner . '</a>';
            }
            continue;
        }
        $inner = kbfPostCleanChildren($child, $inLink);
        if (in_array($tag, ['p', 'h2', 'h3', 'li', 'blockquote', 'strong', 'b', 'em', 'i'], true) && trim(strip_tags($inner, '<img><br>')) === '' && stripos($inner, '<img') === false) {
            continue;
        }
        $out .= '<' . $tag . '>' . $inner . '</' . $tag . '>';
    }
    return $out;
}

// Clean HTML from the editor with the allowlist above
function kbfCleanPostHtml($html) {
    $html = (string) $html;
    if (trim($html) === '') {
        return '';
    }
    if (!class_exists('DOMDocument')) {
        // No DOM extension: keep the text only, as paragraphs
        return kbfPostTextToHtml(kbfPostPlainText($html));
    }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
        . $html . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) {
        return '';
    }
    $clean = kbfPostCleanChildren($body);
    // An unwrapped container leaves "div"; it is not allowed, so unwrap it
    $clean = preg_replace('#</?div>#', '', $clean);
    return trim($clean);
}

// An older plain-text post as editor HTML: paragraphs at blank lines, line
// breaks kept, web addresses as links
function kbfPostTextToHtml($text) {
    $text = str_replace(["\r\n", "\r"], "\n", trim((string) $text));
    if ($text === '') {
        return '';
    }
    $out = '';
    foreach (preg_split("/\n\s*\n/", $text) as $para) {
        $lines = array_map('kbfPostLinkify', explode("\n", trim($para)));
        $out .= '<p>' . implode('<br>', $lines) . '</p>';
    }
    return $out;
}

// The site paths of uploaded news pictures placed in a post's text
function kbfPostInlineImages($html) {
    $found = [];
    if (preg_match_all('#/uploads/announcements/([A-Za-z0-9_-]+)(?:\.(?:d|t))?\.(?:webp|jpe?g|png|gif)#', (string) $html, $m)) {
        foreach ($m[1] as $stem) {
            $found[$stem] = true;
        }
    }
    return array_keys($found);
}

// Delete every file of an uploaded news picture by its name stem (the
// original in any format, its display version and thumbnail), unless
// another post still uses it
function kbfDeletePostImageStem($pdo, $stem, $exceptPostId) {
    if (!preg_match('/^(post|photo|inline)_[A-Za-z0-9]+$/', $stem)) {
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Announcements WHERE AnnouncementId <> ? AND (Content LIKE ? OR FeaturedImage LIKE ?)");
        $like = '%/uploads/announcements/' . $stem . '.%';
        $stmt->execute([(int) $exceptPostId, $like, $like]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
    } catch (PDOException $e) {
        return;
    }
    $dir = dirname(__DIR__) . ANNOUNCEMENT_IMAGE_URL_DIR . '/';
    foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $ext) {
        if (is_file($dir . $stem . '.' . $ext)) {
            kbfDeleteStoredImage(ANNOUNCEMENT_IMAGE_URL_DIR . '/' . $stem . '.' . $ext, ANNOUNCEMENT_IMAGE_URL_DIR);
        }
    }
    foreach (['d', 't'] as $kind) {
        foreach (['webp', 'jpg'] as $ext) {
            if (is_file($dir . $stem . '.' . $kind . '.' . $ext)) {
                @unlink($dir . $stem . '.' . $kind . '.' . $ext);
            }
        }
    }
}

// The site path of a stored news image's display version (or the original)
function kbfPostDisplayUrl($urlPath) {
    $variants = kbfImageVariantFiles(dirname(__DIR__) . $urlPath);
    if ($variants !== null && is_file($variants[0])) {
        return dirname($urlPath) . '/' . basename($variants[0]);
    }
    return $urlPath;
}

// A post's extra photos for pages: [{id, url, caption, sortOrder}]
function kbfPostPhotos($pdo, $postId) {
    try {
        $stmt = $pdo->prepare("SELECT Id, FilePath, Caption, SortOrder FROM AnnouncementPhotos WHERE AnnouncementId = ? ORDER BY SortOrder ASC, Id ASC");
        $stmt->execute([(int) $postId]);
    } catch (PDOException $e) {
        // AnnouncementPhotos not added yet (database/news-editor.sql)
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'id' => (int) $r['Id'],
            'url' => (string) $r['FilePath'],
            'caption' => (string) ($r['Caption'] ?? ''),
            'sortOrder' => (int) $r['SortOrder'],
        ];
    }
    return $out;
}

// The status shown to staff, as SQL on Announcements "a"
function kbfPostStatusSql() {
    return "(CASE WHEN a.PostStatus = 'draft' THEN 'draft'
                  WHEN a.PostStatus = 'scheduled' THEN 'scheduled'
                  WHEN a.IsVisible = 0 THEN 'hidden'
                  WHEN a.IsPublished = 1 THEN 'published'
                  ELSE 'draft' END)";
}

// The date shown to staff for each status
function kbfPostStaffDateSql() {
    return "(CASE WHEN a.PostStatus = 'scheduled' THEN a.ScheduledFor
                  WHEN a.PostStatus = 'draft' OR a.IsPublished = 0 THEN COALESCE(a.UpdatedDate, a.CreatedDate)
                  ELSE COALESCE(a.PublishedDate, a.CreatedDate) END)";
}

// Read and check an uploaded file field "image". Ends the request on error.
function kbfPostUploadedFile() {
    if (!isset($_FILES['image']) || !is_array($_FILES['image']) || is_array($_FILES['image']['error'] ?? null)
        || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        sendJSON(['success' => false, 'message' => 'Choose a picture to upload'], 400);
    }
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $tooBig = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        sendJSON(['success' => false, 'message' => $tooBig ? 'Image too large' : 'Image upload failed'], 400);
    }
    return $file;
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
    // Drafts, scheduled and hidden posts (preview): every one for
    // content.all_news_gallery, only their own for content.own_news_gallery
    if (!$contentStaff) {
        $publicOnlySql = " AND a.IsPublished = 1 AND a.IsVisible = 1";
    } elseif (hasPermission('content.all_news_gallery')) {
        $publicOnlySql = "";
    } else {
        $publicOnlySql = " AND ((a.IsPublished = 1 AND a.IsVisible = 1) OR a.CreatedByAdminId = " . (int) $_SESSION['user_id'] . ")";
    }

    try {
        // ---------- Staff News list (admin/announcements.html) ----------
        // ?staff=1&tab=all|published|drafts|scheduled|hidden&q=&tournament=&author=&page=
        // content.all_news_gallery: every post (automatic promotion posts
        // too); content.own_news_gallery only: their own posts.
        if (isset($_GET['staff']) || isset($_GET['edit'])) {
            if (!isLoggedIn()) {
                sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
            }
            if (!hasAnyPermission(['content.all_news_gallery', 'content.own_news_gallery'])) {
                sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
        }
        if (isset($_GET['edit'])) {
            $postId = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$postId) {
                sendJSON(['success' => false, 'message' => 'Invalid post ID'], 400);
            }
            requirePostAccess($postId);
            $stmt = $pdo->prepare("
                SELECT a.*, " . kbfPostStatusSql() . " AS StaffStatus,
                       t.Name AS TournamentName, t.Status AS TournamentStatus
                FROM Announcements a
                LEFT JOIN Tournaments t ON a.TournamentId = t.TournamentId
                WHERE a.AnnouncementId = ?
            ");
            $stmt->execute([$postId]);
            $p = $stmt->fetch();
            $isHtml = ($p['ContentFormat'] ?? 'text') === 'html';
            $status = (string) ($p['PostStatus'] ?? 'published');
            if ($status === 'published' && (int) $p['IsPublished'] !== 1) {
                $status = 'draft';
            }
            sendJSON([
                'success' => true,
                'post' => [
                    'id' => (int) $p['AnnouncementId'],
                    'title' => (string) $p['Title'],
                    'contentHtml' => $isHtml ? kbfCleanPostHtml($p['Content']) : kbfPostTextToHtml($p['Content']),
                    'contentFormat' => $isHtml ? 'html' : 'text',
                    'status' => $status,
                    'staffStatus' => (string) $p['StaffStatus'],
                    'isVisible' => (int) $p['IsVisible'] === 1,
                    'scheduledFor' => $p['ScheduledFor'] ?? null,
                    'isPinned' => (int) ($p['IsPinned'] ?? 0) === 1,
                    'tournamentId' => $p['TournamentId'] ? (int) $p['TournamentId'] : null,
                    'tournamentName' => $p['TournamentName'] ?? null,
                    'tournamentStatus' => $p['TournamentStatus'] ?? null,
                    'image' => $p['FeaturedImage'] ?: null,
                    'publishedDate' => $p['PublishedDate'] ?? null,
                    'updatedDate' => $p['UpdatedDate'] ?? null,
                    'isPromotion' => stripos((string) $p['Title'], 'player promotion:') !== false,
                ],
                'photos' => kbfPostPhotos($pdo, $postId),
            ]);
        }
        if (isset($_GET['staff'])) {
            $canSeeAll = hasPermission('content.all_news_gallery');
            $userId = (int) $_SESSION['user_id'];
            $statusSql = kbfPostStatusSql();
            $dateSql = kbfPostStaffDateSql();

            // Filters (all tabs): own posts only for organisers, search,
            // tournament and author
            $where = [];
            $params = [];
            if (!$canSeeAll) {
                $where[] = "a.CreatedByAdminId = ?";
                $params[] = $userId;
            }
            $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
            if ($q !== '') {
                $q = mb_substr($q, 0, 100, 'UTF-8');
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
                $where[] = "(a.Title LIKE ? OR a.Content LIKE ?)";
                array_push($params, $like, $like);
            }
            if (isset($_GET['tournament']) && $_GET['tournament'] !== '') {
                $tf = filter_var($_GET['tournament'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$tf) {
                    sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
                }
                $where[] = "a.TournamentId = ?";
                $params[] = (int) $tf;
            }
            if ($canSeeAll && isset($_GET['author']) && $_GET['author'] !== '') {
                $af = filter_var($_GET['author'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($af === false) {
                    sendJSON(['success' => false, 'message' => 'Invalid author'], 400);
                }
                $where[] = "a.CreatedByAdminId = ?";
                $params[] = (int) $af;
            }
            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

            // Counts per tab (with the filters)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS AllPosts,
                       SUM(st = 'published') AS Published, SUM(st = 'draft') AS Drafts,
                       SUM(st = 'scheduled') AS Scheduled, SUM(st = 'hidden') AS Hidden
                FROM (SELECT $statusSql AS st FROM Announcements a $whereSql) x
            ");
            $stmt->execute($params);
            $c = $stmt->fetch();
            $counts = [
                'all' => (int) $c['AllPosts'], 'published' => (int) $c['Published'], 'drafts' => (int) $c['Drafts'],
                'scheduled' => (int) $c['Scheduled'], 'hidden' => (int) $c['Hidden'],
            ];

            $tabs = ['all' => null, 'published' => 'published', 'drafts' => 'draft', 'scheduled' => 'scheduled', 'hidden' => 'hidden'];
            $tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'all';
            if (!array_key_exists($tab, $tabs)) {
                sendJSON(['success' => false, 'message' => 'Invalid tab'], 400);
            }
            $tabWhere = $where;
            $tabParams = $params;
            if ($tabs[$tab] !== null) {
                $tabWhere[] = "$statusSql = ?";
                $tabParams[] = $tabs[$tab];
            }
            $tabWhereSql = $tabWhere ? ' WHERE ' . implode(' AND ', $tabWhere) : '';
            $total = $tab === 'all' ? $counts['all'] : $counts[$tab];

            $perPage = 50;
            $pages = max(1, (int) ceil($total / $perPage));
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            $page = min($page, $pages);

            $stmt = $pdo->prepare("
                SELECT a.AnnouncementId, a.Title, a.FeaturedImage, a.TournamentId, a.CreatedByAdminId,
                       a.IsPinned, $statusSql AS StaffStatus, $dateSql AS StaffDate,
                       t.Name AS TournamentName, t.Status AS TournamentStatus, t.CreatedByAdminId AS TournamentCreator,
                       TRIM(CONCAT(COALESCE(u.FirstName, ''), ' ', COALESCE(u.LastName, ''))) AS AuthorName
                FROM Announcements a
                LEFT JOIN Tournaments t ON a.TournamentId = t.TournamentId
                LEFT JOIN Users u ON a.CreatedByAdminId = u.UserId
                $tabWhereSql
                ORDER BY StaffDate DESC, a.AnnouncementId DESC
                LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
            );
            $stmt->execute($tabParams);
            $posts = [];
            foreach ($stmt->fetchAll() as $r) {
                $isPromotion = stripos((string) $r['Title'], 'player promotion:') !== false;
                $canEdit = $canSeeAll || ((int) $r['CreatedByAdminId'] === $userId
                    && (int) $r['TournamentCreator'] === $userId
                    && in_array($r['TournamentStatus'], ['Approved', 'Completed'], true));
                $posts[] = [
                    'id' => (int) $r['AnnouncementId'],
                    'title' => (string) $r['Title'],
                    'image' => $r['FeaturedImage'] ?: null,
                    'tournamentId' => $r['TournamentId'] ? (int) $r['TournamentId'] : null,
                    'tournamentName' => $r['TournamentName'] ?? null,
                    'authorId' => (int) $r['CreatedByAdminId'],
                    'authorName' => (string) $r['AuthorName'] !== '' ? (string) $r['AuthorName'] : ($isPromotion ? 'Automatic' : ''),
                    'date' => $r['StaffDate'],
                    'status' => (string) $r['StaffStatus'],
                    'isPinned' => (int) $r['IsPinned'] === 1,
                    'isPromotion' => $isPromotion,
                    'canEdit' => $canEdit,
                ];
            }

            // Filter choices: tournaments with posts, and authors (all-news staff)
            $scopeSql = $canSeeAll ? '' : ' AND a.CreatedByAdminId = ?';
            $scopeParams = $canSeeAll ? [] : [$userId];
            $stmt = $pdo->prepare("
                SELECT DISTINCT t.TournamentId, t.Name, t.TournamentDate
                FROM Announcements a JOIN Tournaments t ON a.TournamentId = t.TournamentId
                WHERE 1 = 1$scopeSql
                ORDER BY t.TournamentDate DESC, t.TournamentId DESC
            ");
            $stmt->execute($scopeParams);
            $tournaments = array_map(function ($t) {
                return ['id' => (int) $t['TournamentId'], 'name' => (string) $t['Name']];
            }, $stmt->fetchAll());
            $authors = [];
            if ($canSeeAll) {
                $rows = $pdo->query("
                    SELECT DISTINCT a.CreatedByAdminId AS Id,
                           TRIM(CONCAT(COALESCE(u.FirstName, ''), ' ', COALESCE(u.LastName, ''))) AS Name
                    FROM Announcements a LEFT JOIN Users u ON a.CreatedByAdminId = u.UserId
                    ORDER BY Name
                ")->fetchAll();
                foreach ($rows as $r) {
                    $authors[] = ['id' => (int) $r['Id'], 'name' => (string) $r['Name'] !== '' ? (string) $r['Name'] : 'Automatic'];
                }
            }

            sendJSON([
                'success' => true,
                'posts' => $posts,
                'counts' => $counts,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'pages' => $pages,
                'tournaments' => $tournaments,
                'authors' => $authors,
                'canSeeAll' => $canSeeAll,
            ]);
        }

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
                // HTML posts: cleaned again on the way out (defence in depth)
                if (($announcement['ContentFormat'] ?? 'text') === 'html') {
                    $announcement['Content'] = kbfCleanPostHtml($announcement['Content']);
                }
                unset($announcement['ShareImagePath']);
                // The extra photos under the text
                $extras['photos'] = kbfPostPhotos($pdo, $announcementId);
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

            // Get announcements: pinned posts first (newest pinned first),
            // then newest first (published date, else created
            // date). Content is a short plain-text excerpt in lists (HTML
            // tags removed, entities kept); the full text comes with ?id=
            $stmt = $pdo->prepare("
                SELECT a.AnnouncementId, a.Title, LEFT(a.Content, 4000) AS Content, a.FeaturedImage,
                       a.CreatedDate, a.PublishedDate, a.IsVisible,
                       COALESCE(a.IsPinned, 0) AS IsPinned,
                       " . $authorColumn . "
                       a.TournamentId,
                       t.Name as TournamentName,
                       t.TournamentDate
                FROM Announcements a
                " . $authorJoin . "
                LEFT JOIN Tournaments t ON a.TournamentId = t.TournamentId
                WHERE a.IsPublished = 1 AND a.IsVisible = 1" . $filterSql . "
                ORDER BY COALESCE(a.IsPinned, 0) DESC, COALESCE(a.PublishedDate, a.CreatedDate) DESC, a.AnnouncementId DESC
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

// ============================================
// NEWS EDITOR ACTIONS (POST ?action=)
//   save         JSON {postId (0 = new), title, content (HTML), tournamentId,
//                mode 'publish'|'schedule'|'draft', scheduledFor
//                'YYYY-MM-DDTHH:MM', isPinned, photos [{id, caption}] in
//                order, autosave}. Autosave only saves drafts (409 with
//                skipped: true for any other post) and never changes the
//                status, schedule or pin.
//   upload       multipart postId, kind 'main'|'photo'|'inline', image
//   removeImage  JSON {postId}: the main picture
//   removePhoto  JSON {postId, photoId}
// Login, security token and a content permission were checked above;
// every action on an existing post goes through requirePostAccess().
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    $action = is_string($_GET['action']) ? $_GET['action'] : '';
    $canSeeAll = hasPermission('content.all_news_gallery');
    $userId = (int) $_SESSION['user_id'];

    try {
        if ($action === 'save') {
            $data = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($data)) {
                sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
            }
            foreach (['title', 'content', 'mode', 'scheduledFor'] as $field) {
                if (isset($data[$field]) && !is_string($data[$field])) {
                    sendJSON(['success' => false, 'message' => 'Invalid value for ' . $field], 400);
                }
            }
            $postId = filter_var($data['postId'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($postId === false) {
                sendJSON(['success' => false, 'message' => 'Invalid post ID'], 400);
            }
            $autosave = !empty($data['autosave']);
            $mode = $autosave ? 'draft' : (string) ($data['mode'] ?? 'draft');
            if (!in_array($mode, ['publish', 'schedule', 'draft'], true)) {
                sendJSON(['success' => false, 'message' => 'Invalid choice'], 400);
            }

            $existing = null;
            if ($postId > 0) {
                requirePostAccess($postId);
                $stmt = $pdo->prepare("SELECT * FROM Announcements WHERE AnnouncementId = ?");
                $stmt->execute([$postId]);
                $existing = $stmt->fetch();
                if ($autosave && ($existing['PostStatus'] ?? '') !== 'draft') {
                    sendJSON(['success' => false, 'skipped' => true, 'message' => 'Only drafts are saved automatically'], 409);
                }
            }

            // Title (never < or >)
            $title = trim(str_replace(['<', '>'], '', (string) ($data['title'] ?? '')));
            $minTitle = $mode === 'draft' ? 1 : 3;
            if (kbfTextLength($title) < $minTitle || kbfTextLength($title) > 200) {
                sendJSON(['success' => false, 'message' => $mode === 'draft'
                    ? 'Enter a title of up to 200 characters'
                    : 'Title must be between 3 and 200 characters'], 400);
            }

            // Text: cleaned with the allowlist
            $raw = (string) ($data['content'] ?? '');
            if (strlen($raw) > 200000) {
                sendJSON(['success' => false, 'message' => 'The text is too long'], 400);
            }
            $content = kbfCleanPostHtml($raw);
            if (strlen($content) > 60000) {
                sendJSON(['success' => false, 'message' => 'The text is too long. Please shorten it.'], 400);
            }
            $plainLength = kbfTextLength(kbfPostPlainText($content));
            if ($mode !== 'draft' && $plainLength < 10) {
                sendJSON(['success' => false, 'message' => 'Write at least 10 characters of text'], 400);
            }

            // Tournament (organisers: one of their own Approved or Completed)
            $tournamentId = filter_var($data['tournamentId'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($tournamentId === false) {
                sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
            }
            $tournamentId = $tournamentId > 0 ? $tournamentId : null;
            if ($tournamentId !== null) {
                $stmt = $pdo->prepare("SELECT TournamentId FROM Tournaments WHERE TournamentId = ?");
                $stmt->execute([$tournamentId]);
                if (!$stmt->fetch()) {
                    sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
                }
            }
            if (!$canSeeAll && !canPostAboutOwnTournament($tournamentId)) {
                sendJSON(['success' => false, 'message' => 'Posts must be linked to one of your own approved tournaments'], 403);
            }

            // Status
            $status = $existing ? (string) $existing['PostStatus'] : 'draft';
            $scheduledFor = $existing['ScheduledFor'] ?? null;
            $publishedDate = $existing['PublishedDate'] ?? null;
            $isPublished = $existing ? (int) $existing['IsPublished'] : 0;
            if (!$autosave) {
                if ($mode === 'publish') {
                    if ($status !== 'published' || $isPublished !== 1 || !$publishedDate) {
                        $publishedDate = date('Y-m-d H:i:s');
                    }
                    $status = 'published';
                    $isPublished = 1;
                    $scheduledFor = null;
                } elseif ($mode === 'schedule') {
                    $when = DateTime::createFromFormat('Y-m-d\TH:i', (string) ($data['scheduledFor'] ?? ''))
                        ?: DateTime::createFromFormat('Y-m-d H:i', (string) ($data['scheduledFor'] ?? ''));
                    if (!$when) {
                        sendJSON(['success' => false, 'message' => 'Pick a date and time for the post'], 400);
                    }
                    if ($when->getTimestamp() <= time() + 60) {
                        sendJSON(['success' => false, 'message' => 'Pick a time in the future'], 400);
                    }
                    if ($when->getTimestamp() > time() + 366 * 86400) {
                        sendJSON(['success' => false, 'message' => 'Posts can be scheduled up to a year ahead'], 400);
                    }
                    $status = 'scheduled';
                    $isPublished = 0;
                    $scheduledFor = $when->format('Y-m-d H:i:00');
                    $publishedDate = null;
                } else {
                    $status = 'draft';
                    $isPublished = 0;
                    $scheduledFor = null;
                }
            }

            // Pinning: content.all_news_gallery only; otherwise unchanged
            $isPinned = $existing ? (int) ($existing['IsPinned'] ?? 0) : 0;
            if ($canSeeAll && !$autosave && array_key_exists('isPinned', $data)) {
                $isPinned = !empty($data['isPinned']) ? 1 : 0;
            }

            if ($existing === null) {
                // A repeat of the same one-time request id gets the first answer back
                require_once __DIR__ . '/request-ids.php';
                kbfRequestIdBegin($pdo, 'news_post');
                $stmt = $pdo->prepare("
                    INSERT INTO Announcements
                        (Title, Content, ContentFormat, PostStatus, ScheduledFor, IsPinned, FeaturedImage,
                         TournamentId, CreatedByAdminId, CreatedDate, UpdatedDate, PublishedDate, IsPublished, IsVisible)
                    VALUES (?, ?, 'html', ?, ?, ?, NULL, ?, ?, NOW(), NOW(), ?, ?, 1)
                ");
                $stmt->execute([$title, $content, $status, $scheduledFor, $isPinned, $tournamentId, $userId, $publishedDate, $isPublished]);
                $postId = (int) $pdo->lastInsertId();
                auditLog('post_created', 'post', $postId,
                    'Post "' . mb_substr($title, 0, 80) . '" (' . $status . ')' . ($tournamentId ? ' (tournament ' . (int) $tournamentId . ')' : ''));
            } else {
                $stmt = $pdo->prepare("
                    UPDATE Announcements
                    SET Title = ?, Content = ?, ContentFormat = 'html', PostStatus = ?, ScheduledFor = ?, IsPinned = ?,
                        TournamentId = ?, UpdatedDate = NOW(), PublishedDate = ?, IsPublished = ?
                    WHERE AnnouncementId = ?
                ");
                $stmt->execute([$title, $content, $status, $scheduledFor, $isPinned, $tournamentId, $publishedDate, $isPublished, $postId]);
                if (!$autosave) {
                    $was = (string) $existing['PostStatus'];
                    $detail = 'Post ' . $postId . ' saved (' . $status . ')';
                    if ($was !== $status) {
                        $detail = 'Post ' . $postId . ': ' . $was . ' -> ' . $status;
                    }
                    if ($status === 'scheduled') {
                        $detail .= ' for ' . $scheduledFor;
                    }
                    auditLog($status === 'published' && $was !== 'published' ? 'post_published'
                        : ($status === 'scheduled' && $was !== 'scheduled' ? 'post_scheduled' : 'post_edited'),
                        'post', $postId, $detail);
                }
            }

            // Photo captions and order
            if (isset($data['photos']) && is_array($data['photos'])) {
                $upd = $pdo->prepare("UPDATE AnnouncementPhotos SET Caption = ?, SortOrder = ? WHERE Id = ? AND AnnouncementId = ?");
                $order = 0;
                foreach (array_slice($data['photos'], 0, 50) as $photo) {
                    if (!is_array($photo)) {
                        continue;
                    }
                    $photoId = filter_var($photo['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (!$photoId) {
                        continue;
                    }
                    $caption = is_string($photo['caption'] ?? null) ? kbfCleanText($photo['caption']) : '';
                    $caption = mb_substr(str_replace(['<', '>'], '', $caption), 0, 200, 'UTF-8');
                    $upd->execute([$caption === '' ? null : $caption, $order++, $photoId, $postId]);
                }
            }

            $messages = ['published' => 'Post published', 'scheduled' => 'Post scheduled', 'draft' => 'Draft saved'];
            sendJSON([
                'success' => true,
                'postId' => $postId,
                'announcementId' => $postId,
                'status' => $status,
                'scheduledFor' => $scheduledFor,
                'savedAt' => date('H:i'),
                'message' => $messages[$status] ?? 'Saved',
            ]);
        }

        if ($action === 'upload') {
            $postId = filter_var($_POST['postId'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$postId) {
                sendJSON(['success' => false, 'message' => 'Save the post first'], 400);
            }
            requirePostAccess($postId);
            $kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
            if (!in_array($kind, ['main', 'photo', 'inline'], true)) {
                sendJSON(['success' => false, 'message' => 'Invalid picture type'], 400);
            }
            if ($kind === 'photo') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM AnnouncementPhotos WHERE AnnouncementId = ?");
                $stmt->execute([$postId]);
                if ((int) $stmt->fetchColumn() >= KBF_POST_MAX_PHOTOS) {
                    sendJSON(['success' => false, 'message' => 'A post can have up to ' . KBF_POST_MAX_PHOTOS . ' more photos'], 400);
                }
            }
            $file = kbfPostUploadedFile();
            $prefix = ['main' => 'post_', 'photo' => 'photo_', 'inline' => 'inline_'][$kind];
            // Checked, re-encoded (no location data), WebP with a display
            // version and thumbnail (api/image-helpers.php)
            list($path, $error) = kbfStoreImage($file['tmp_name'], true, $uploadsDir,
                ANNOUNCEMENT_IMAGE_URL_DIR, $prefix, kbfUploadLimitBytes('upload_mb_news', 5));
            if ($error !== null) {
                sendJSON(['success' => false, 'message' => $error], 400);
            }

            if ($kind === 'main') {
                $stmt = $pdo->prepare("SELECT FeaturedImage FROM Announcements WHERE AnnouncementId = ?");
                $stmt->execute([$postId]);
                $old = $stmt->fetchColumn();
                try {
                    $stmt = $pdo->prepare("UPDATE Announcements SET FeaturedImage = ?, UpdatedDate = NOW() WHERE AnnouncementId = ?");
                    $stmt->execute([$path, $postId]);
                } catch (PDOException $e) {
                    kbfDeleteStoredImage($path, ANNOUNCEMENT_IMAGE_URL_DIR);
                    throw $e;
                }
                if (is_string($old) && $old !== '' && $old !== $path) {
                    kbfDeleteStoredImage($old, ANNOUNCEMENT_IMAGE_URL_DIR);
                }
                // The link preview picture follows the main picture
                require_once __DIR__ . '/share-images.php';
                kbfUpdateShareImage($pdo, 'post', $postId, $path);
                auditLog('post_edited', 'post', $postId, 'Post ' . $postId . ': main picture');
                sendJSON(['success' => true, 'image' => $path]);
            }

            if ($kind === 'photo') {
                $variants = kbfImageVariantFiles(dirname(__DIR__) . $path);
                $display = $variants && is_file($variants[0]) ? ANNOUNCEMENT_IMAGE_URL_DIR . '/' . basename($variants[0]) : null;
                $thumb = $variants && is_file($variants[1]) ? ANNOUNCEMENT_IMAGE_URL_DIR . '/' . basename($variants[1]) : null;
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(SortOrder), -1) + 1 FROM AnnouncementPhotos WHERE AnnouncementId = ?");
                    $stmt->execute([$postId]);
                    $sort = (int) $stmt->fetchColumn();
                    $stmt = $pdo->prepare("
                        INSERT INTO AnnouncementPhotos (AnnouncementId, FilePath, DisplayPath, ThumbPath, Caption, SortOrder, CreatedDate)
                        VALUES (?, ?, ?, ?, NULL, ?, NOW())
                    ");
                    $stmt->execute([$postId, $path, $display, $thumb, $sort]);
                } catch (PDOException $e) {
                    kbfDeleteStoredImage($path, ANNOUNCEMENT_IMAGE_URL_DIR);
                    throw $e;
                }
                $photoId = (int) $pdo->lastInsertId();
                sendJSON(['success' => true, 'photo' => ['id' => $photoId, 'url' => $path, 'caption' => '', 'sortOrder' => $sort]]);
            }

            // Inline: the display version goes in the text
            sendJSON(['success' => true, 'url' => kbfPostDisplayUrl($path), 'original' => $path]);
        }

        if ($action === 'removeImage' || $action === 'removePhoto') {
            $data = json_decode((string) file_get_contents('php://input'), true);
            $postId = is_array($data) ? filter_var($data['postId'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if (!$postId) {
                sendJSON(['success' => false, 'message' => 'Invalid post ID'], 400);
            }
            requirePostAccess($postId);

            if ($action === 'removeImage') {
                $stmt = $pdo->prepare("SELECT FeaturedImage FROM Announcements WHERE AnnouncementId = ?");
                $stmt->execute([$postId]);
                $old = $stmt->fetchColumn();
                $stmt = $pdo->prepare("UPDATE Announcements SET FeaturedImage = NULL, UpdatedDate = NOW() WHERE AnnouncementId = ?");
                $stmt->execute([$postId]);
                if (is_string($old) && $old !== '') {
                    kbfDeleteStoredImage($old, ANNOUNCEMENT_IMAGE_URL_DIR);
                }
                require_once __DIR__ . '/share-images.php';
                kbfUpdateShareImage($pdo, 'post', $postId, null);
                auditLog('post_edited', 'post', $postId, 'Post ' . $postId . ': main picture removed');
                sendJSON(['success' => true, 'message' => 'Picture removed']);
            }

            $photoId = filter_var($data['photoId'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$photoId) {
                sendJSON(['success' => false, 'message' => 'Invalid photo'], 400);
            }
            $stmt = $pdo->prepare("SELECT FilePath FROM AnnouncementPhotos WHERE Id = ? AND AnnouncementId = ?");
            $stmt->execute([$photoId, $postId]);
            $file = $stmt->fetchColumn();
            if ($file === false) {
                sendJSON(['success' => false, 'message' => 'Photo not found'], 404);
            }
            $stmt = $pdo->prepare("DELETE FROM AnnouncementPhotos WHERE Id = ? AND AnnouncementId = ?");
            $stmt->execute([$photoId, $postId]);
            kbfDeleteStoredImage((string) $file, ANNOUNCEMENT_IMAGE_URL_DIR);
            sendJSON(['success' => true, 'message' => 'Photo removed']);
        }

        sendJSON(['success' => false, 'message' => 'Unknown action'], 400);
    } catch (PDOException $e) {
        error_log('announcements.php ' . $action . ': ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// POST - Create announcement (older form; new posts use ?action=save)
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

        $stmt = $pdo->prepare("SELECT * FROM Announcements WHERE AnnouncementId = ?");
        $stmt->execute([$announcementId]);
        $old = $stmt->fetch();
        if (!$old) {
            sendJSON(['success' => false, 'message' => 'Announcement not found'], 404);
        }
        $photoFiles = [];
        try {
            $stmt = $pdo->prepare("SELECT FilePath FROM AnnouncementPhotos WHERE AnnouncementId = ?");
            $stmt->execute([$announcementId]);
            $photoFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // AnnouncementPhotos not added yet (database/news-editor.sql)
        }

        // The post and its extra photos are deleted
        $pdo->beginTransaction();
        try {
            if ($photoFiles) {
                $stmt = $pdo->prepare("DELETE FROM AnnouncementPhotos WHERE AnnouncementId = ?");
                $stmt->execute([$announcementId]);
            }
            $stmt = $pdo->prepare("DELETE FROM Announcements WHERE AnnouncementId = ?");
            $stmt->execute([$announcementId]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Then every image file of the post: the main picture, the extra
        // photos, pictures placed in the text (unless another post uses
        // them) and the link preview picture
        if (is_string($old['FeaturedImage']) && $old['FeaturedImage'] !== '') {
            kbfDeleteStoredImage($old['FeaturedImage'], ANNOUNCEMENT_IMAGE_URL_DIR);
        }
        foreach ($photoFiles as $file) {
            kbfDeleteStoredImage((string) $file, ANNOUNCEMENT_IMAGE_URL_DIR);
        }
        foreach (kbfPostInlineImages($old['Content']) as $stem) {
            if (strpos($stem, 'inline_') === 0) {
                kbfDeletePostImageStem($pdo, $stem, $announcementId);
            }
        }
        if (!empty($old['ShareImagePath'])) {
            require_once __DIR__ . '/share-images.php';
            kbfDeleteShareImage($old['ShareImagePath']);
        }

        auditLog('post_deleted', 'post', $announcementId,
            'Post ' . (int) $announcementId . ' "' . mb_substr((string) $old['Title'], 0, 80) . '" deleted with its pictures');

        sendJSON(['success' => true, 'message' => 'Announcement deleted successfully']);
    } catch(PDOException $e) {
        error_log('Announcement deletion error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
?>