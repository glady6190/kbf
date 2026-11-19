<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';

// ============================================
// TOURNAMENTS API
//   GET                       list (filters: status, clubId, mine=1)
//   GET ?id=                  single tournament with matches
//   GET ?lastOrganiser=1      OrganiserName of the last tournament this user
//                             created (pre-fills the create form)
//   GET ?organiserNames=1     distinct organiser names, most used first (max 200)
//                             tournaments.organise_own or tournaments.manage_all
//   GET ?action=similar&name=&date=   tournaments within 3 days with a similar
//                             name (duplicate warning on the create form;
//                             api/tournament-helpers.php kbfSimilarTournaments)
//                             tournaments.organise_own or tournaments.manage_all
//   POST                      create (JSON, or multipart when a poster is attached)
//                             tournaments.organise_own or tournaments.manage_all
//   POST action=updateDetails update only poster, registration and contact details
//                             (multipart). Never changes Status, no re-approval.
//                             manage_all: any; organise_own: own while Pending/Approved
//   PUT                       approve / status: tournaments.approve
//                             name, date, venue, location, postcode, categories,
//                             OrganiserName: tournaments.manage_all
//   DELETE ?id=               tournaments.manage_all, Pending or Rejected, no matches
//
// Admin Tournaments page (tournaments.approve or tournaments.manage_all):
//   GET ?action=adminList     tab (upcoming|due|waiting|completed|rejected|all),
//                             q, organiser, season (this|all|YYYY-Hn), offset;
//                             tab counts, 50 a page (statuses: api/tournament-helpers.php)
//   GET ?action=adminTournament&id=   status, reminders, results waiting, news posts
//   GET ?action=history&id=   AuditLog entries about the tournament, newest first
//   POST {action:'remindOrganiser', tournamentId}   results_overdue email to the
//                             creator, Results due only, once in 24 hours
//   POST {action:'changeStatus', tournamentId, status, reason}
//                             tournaments.manage_all: Approved, Completed or
//                             Cancelled (reason required for Cancelled)
//
// "Own tournament": Tournaments.CreatedByAdminId is the current user.
// Pending and Rejected tournaments, and creator/approver names, are shown only
// to staff with tournaments.approve or tournaments.manage_all; organisers
// (tournaments.organise_own) also see their own Pending and Rejected ones.
// ============================================

const TOURNAMENT_POSTER_URL_DIR = '/uploads/tournaments/';
// Poster size limit: Site settings upload_mb_poster (4 MB by default)
function tournamentPosterMaxBytes() {
    return kbfUploadLimitBytes('upload_mb_poster', 4);
}

function tournamentPosterTooBigMessage() {
    return 'Poster must be ' . (tournamentPosterMaxBytes() / 1048576) . ' MB or smaller';
}

// Auth runs BEFORE CSRF (same order as api/matches.php) so an expired session
// returns a clear 401 instead of a misleading 403 "Invalid security token".
// Permission and ownership checks follow in each handler.
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
    if (!isLoggedIn()) {
        sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}

// ============================================
// HELPERS
// ============================================

// Staff who see every tournament (including Pending and Rejected) and the
// creator and approver names.
function canSeeAllTournaments() {
    return hasAnyPermission(['tournaments.approve', 'tournaments.manage_all']);
}

// Organisers see their own Pending and Rejected tournaments.
function canSeeOwnTournaments() {
    return hasPermission('tournaments.organise_own');
}

function tournamentTextLength($text) {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

// Plain single-line text: trimmed, control characters and line breaks removed.
function cleanTournamentLine($value) {
    $text = is_string($value) ? $value : '';
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', (string) $text);
    return trim((string) $text);
}

// Plain multi-line text: no HTML, no control characters, line breaks kept.
function cleanTournamentMultiline($value) {
    $text = is_string($value) ? $value : '';
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = strip_tags($text);
    $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
    return trim((string) $text);
}

// Returns a safe http/https URL, null for empty input, or false when invalid.
// Missing scheme gets https:// added. Any other scheme (javascript:, data:, ...) is rejected.
// Same rules as normaliseSponsorUrl in api/sponsors.php, with a 500 character limit.
function normaliseRegistrationUrl($value) {
    $max = 500;
    $url = trim(is_string($value) ? $value : '');
    if ($url === '') {
        return null;
    }
    if (strlen($url) > $max || preg_match('/[\s\x00-\x1F\x7F<>"\'`]/', $url)) {
        return false;
    }

    if (preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $url, $m)) {
        if (!in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return false;
        }
    } elseif (preg_match('#^[a-z][a-z0-9+.\-]*:(?!\d)#i', $url)) {
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
    return strlen($url) > $max ? false : $url;
}

// YYYY-MM-DD that is a real calendar date, or false.
function parseTournamentDateValue($value) {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : false;
}

// HH:MM or HH:MM:SS (24 hour), returned as HH:MM:00, or false.
function parseTournamentTimeValue($value) {
    if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m)) {
        return false;
    }
    return $m[1] . ':' . $m[2] . ':00';
}

// Read and validate the optional detail fields.
// $partial = true: only fields present in $data are returned (used by updateDetails).
// Returns [array columnValues, string|null error].
function readTournamentExtras(array $data, $tournamentDate, $partial) {
    $fields = [
        'registrationUrl'       => 'RegistrationUrl',
        'registrationCloseDate' => 'RegistrationCloseDate',
        'entryFee'              => 'EntryFee',
        'startTime'             => 'StartTime',
        'description'           => 'Description',
        'contactName'           => 'ContactName',
        'contactPhone'          => 'ContactPhone',
    ];
    $values = [];

    foreach ($fields as $key => $column) {
        if ($partial && !array_key_exists($key, $data)) {
            continue;
        }
        $raw = $data[$key] ?? null;
        if ($raw !== null && !is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return [[], 'Invalid value for ' . $key];
        }
        $raw = $raw === null ? '' : (string) $raw;

        switch ($key) {
            case 'registrationUrl':
                $url = normaliseRegistrationUrl($raw);
                if ($url === false) {
                    return [[], 'Registration link must be a valid http or https address (500 characters or fewer)'];
                }
                $values[$column] = $url;
                break;

            case 'registrationCloseDate':
                $raw = trim($raw);
                if ($raw === '') {
                    $values[$column] = null;
                    break;
                }
                $date = parseTournamentDateValue($raw);
                if ($date === false) {
                    return [[], 'Registration closing date is not a valid date'];
                }
                if ($tournamentDate && $date > $tournamentDate) {
                    return [[], 'Registration cannot close after the tournament date'];
                }
                $values[$column] = $date;
                break;

            case 'startTime':
                $raw = trim($raw);
                if ($raw === '') {
                    $values[$column] = null;
                    break;
                }
                $time = parseTournamentTimeValue($raw);
                if ($time === false) {
                    return [[], 'Start time is not a valid time'];
                }
                $values[$column] = $time;
                break;

            case 'entryFee':
                $text = cleanTournamentLine($raw);
                if (tournamentTextLength($text) > 100) {
                    return [[], 'Entry fee must be 100 characters or fewer'];
                }
                $values[$column] = $text === '' ? null : $text;
                break;

            case 'description':
                $text = cleanTournamentMultiline($raw);
                if (tournamentTextLength($text) > 2000) {
                    return [[], 'About this tournament must be 2,000 characters or fewer'];
                }
                $values[$column] = $text === '' ? null : $text;
                break;

            case 'contactName':
                $text = cleanTournamentLine($raw);
                if (tournamentTextLength($text) > 100) {
                    return [[], 'Contact name must be 100 characters or fewer'];
                }
                $values[$column] = $text === '' ? null : $text;
                break;

            case 'contactPhone':
                $text = cleanTournamentLine($raw);
                if ($text === '') {
                    $values[$column] = null;
                    break;
                }
                if (strlen($text) > 40 || !preg_match('/^[0-9 +()\-]+$/', $text) || !preg_match('/\d/', $text)) {
                    return [[], 'Contact phone may only contain digits, spaces, +, brackets and hyphens (40 characters or fewer)'];
                }
                $values[$column] = $text;
                break;
        }
    }

    return [$values, null];
}

// True when a poster file was sent with the request.
function hasPosterUpload() {
    return isset($_FILES['poster']) && ($_FILES['poster']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

// Validate and store an uploaded poster. Same checks as storeSponsorLogo in
// api/sponsors.php: real type via finfo, must load via getimagesize, size limit,
// random file name. Returns [string|null path, string|null error].
function storeTournamentPoster(array $file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Poster upload failed';
        if (isset($file['error'])) {
            switch ($file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMsg = tournamentPosterTooBigMessage();
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMsg = 'Poster upload incomplete';
                    break;
            }
        }
        return [null, $errorMsg];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Invalid upload'];
    }

    if ($file['size'] > tournamentPosterMaxBytes()) {
        return [null, tournamentPosterTooBigMessage()];
    }

    // SECURITY: Validate file type by actual content, not just MIME (no SVG)
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes, true)) {
        return [null, 'Only JPG, PNG and WebP posters are allowed'];
    }

    // SECURITY: Additional validation - check image can be loaded
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || !in_array($imageInfo['mime'], $allowedTypes, true)) {
        return [null, 'Invalid image file'];
    }

    // SECURITY: Generate completely random filename (no user input)
    $extension = $imageInfo['mime'] === 'image/jpeg' ? 'jpg' :
                ($imageInfo['mime'] === 'image/png' ? 'png' : 'webp');
    $filename = 'tournament_' . bin2hex(random_bytes(16)) . '.' . $extension;

    $uploadDir = __DIR__ . '/..' . TOURNAMENT_POSTER_URL_DIR;
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return [null, 'Failed to create upload directory'];
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        return [null, 'Failed to save poster'];
    }

    // Display version and thumbnail (a problem never stops the upload)
    require_once __DIR__ . '/image-helpers.php';
    kbfMakeImageVariants($uploadDir . $filename);

    return [TOURNAMENT_POSTER_URL_DIR . $filename, null];
}

// Delete a poster file, only ever inside the tournament posters folder.
// Images belonging to linked posts live elsewhere and are never touched.
function deleteTournamentPoster($posterPath) {
    if (!is_string($posterPath) || strpos($posterPath, TOURNAMENT_POSTER_URL_DIR) !== 0) {
        return;
    }
    $basename = basename($posterPath);
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $basename) || $basename === '.' || $basename === '..') {
        return;
    }
    $fullPath = __DIR__ . '/..' . TOURNAMENT_POSTER_URL_DIR . $basename;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
    require_once __DIR__ . '/image-helpers.php';
    kbfDeleteImageVariants($fullPath);
}

// Adds PosterUrl and PosterSource ('tournament' | 'post' | null) to each row.
// Without its own poster, a tournament borrows the featured image of its
// linked post (PostId), when that post is published and visible.
function attachTournamentPosters(PDO $pdo, array &$rows) {
    $postIds = [];
    foreach ($rows as $row) {
        if (empty($row['PosterPath']) && !empty($row['PostId'])) {
            $postIds[] = (int) $row['PostId'];
        }
    }

    $postImages = [];
    if (!empty($postIds)) {
        $postIds = array_values(array_unique($postIds));
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $pdo->prepare("
            SELECT AnnouncementId, FeaturedImage
            FROM Announcements
            WHERE AnnouncementId IN ($placeholders)
              AND IsVisible = 1 AND IsPublished = 1
              AND FeaturedImage IS NOT NULL AND FeaturedImage <> ''
        ");
        $stmt->execute($postIds);
        // A post image that is an under-18 player's photo is never used as a
        // poster for visitors (api/config-helpers.php)
        foreach (kbfPublicPostImages($pdo, $stmt->fetchAll()) as $post) {
            if (empty($post['FeaturedImage'])) {
                continue;
            }
            // Only site-relative upload paths are passed on
            if (preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $post['FeaturedImage']) && strpos($post['FeaturedImage'], '..') === false) {
                $postImages[(int) $post['AnnouncementId']] = $post['FeaturedImage'];
            }
        }
    }

    foreach ($rows as &$row) {
        if (!empty($row['PosterPath'])) {
            $row['PosterUrl'] = $row['PosterPath'];
            $row['PosterSource'] = 'tournament';
        } elseif (!empty($row['PostId']) && isset($postImages[(int) $row['PostId']])) {
            $row['PosterUrl'] = $postImages[(int) $row['PostId']];
            $row['PosterSource'] = 'post';
        } else {
            $row['PosterUrl'] = null;
            $row['PosterSource'] = null;
        }
    }
    unset($row);
}

// Grouping key for an organiser name: trimmed, repeated spaces collapsed,
// upper and lower case ignored.
function organiserNameKey($name) {
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

// Organiser names used on tournaments, grouped by organiserNameKey().
// Returns [key => ['name' => most common spelling, 'uses' => total]], most used first.
// $excludeTournamentId leaves one tournament out (used when editing it).
function organiserNameGroups(PDO $pdo, $excludeTournamentId = 0) {
    $stmt = $pdo->prepare("
        SELECT " . organiserNameSql('t', 'c') . " AS OrganiserName
        FROM Tournaments t
        LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
        WHERE t.TournamentId <> ?
        ORDER BY t.CreatedDate DESC, t.TournamentId DESC
    ");
    $stmt->execute([(int) $excludeTournamentId]);

    $groups = [];
    while (($row = $stmt->fetch()) !== false) {
        $spelling = trim((string) preg_replace('/\s+/u', ' ', (string) $row['OrganiserName']));
        if ($spelling === '') {
            continue;
        }
        $key = organiserNameKey($spelling);
        if (!isset($groups[$key])) {
            $groups[$key] = ['uses' => 0, 'spellings' => []];
        }
        $groups[$key]['uses']++;
        $groups[$key]['spellings'][$spelling] = ($groups[$key]['spellings'][$spelling] ?? 0) + 1;
    }

    $result = [];
    foreach ($groups as $key => $group) {
        // Most common spelling; on a tie, the most recently used one (first seen)
        $best = null;
        foreach ($group['spellings'] as $spelling => $count) {
            if ($best === null || $count > $group['spellings'][$best]) {
                $best = (string) $spelling;
            }
        }
        $result[$key] = ['name' => $best, 'uses' => $group['uses']];
    }
    uasort($result, function ($a, $b) {
        return ($b['uses'] <=> $a['uses']) ?: strcasecmp($a['name'], $b['name']);
    });
    return $result;
}

// A cleaned organiser name, or the existing spelling when it matches an
// existing name apart from upper and lower case.
function canonicalOrganiserName(PDO $pdo, $name, $excludeTournamentId = 0) {
    try {
        $groups = organiserNameGroups($pdo, $excludeTournamentId);
    } catch (PDOException $e) {
        error_log('Organiser name lookup error: ' . $e->getMessage());
        return $name;
    }
    $key = organiserNameKey($name);
    return isset($groups[$key]) ? $groups[$key]['name'] : $name;
}

// The eight extra columns, selected on both list and single tournament.
const TOURNAMENT_EXTRA_COLUMNS = "t.PosterPath, t.RegistrationUrl, t.RegistrationCloseDate, t.EntryFee,
           t.StartTime, t.Description, t.ContactName, t.ContactPhone";

// ============================================
// ADMIN TOURNAMENTS PAGE (GET; the caller has checked the login and
// tournaments.approve or tournaments.manage_all, and loaded
// tournament-helpers.php and report-helpers.php)
// ============================================
const ADMIN_TOURNAMENT_TABS = ['upcoming', 'due', 'waiting', 'completed', 'rejected', 'all'];
const ADMIN_TOURNAMENT_PAGE_SIZE = 50;

// "First Last" of a joined user, or ''
function adminPersonName($first, $last) {
    return trim(trim((string) $first) . ' ' . trim((string) $last));
}

function adminTournamentId() {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
    }
    return (int) $id;
}

// The list: tab counts, filters and one page of rows
function adminTournamentList(PDO $pdo, $currentUserId) {
    $tab = in_array($_GET['tab'] ?? '', ADMIN_TOURNAMENT_TABS, true) ? $_GET['tab'] : 'all';
    $q = cleanTournamentLine($_GET['q'] ?? '');
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100);
    $organiser = cleanTournamentLine($_GET['organiser'] ?? '');
    $offset = kbfOffsetParam();

    // Season: "This season" by default, "All seasons", or one season key
    $months = kbfSeasonSettings($pdo)['months'];
    $current = kbfSeasonAt($months);
    $seasonParam = is_string($_GET['season'] ?? null) ? $_GET['season'] : 'this';
    $range = null;
    if ($seasonParam === 'all') {
        $season = 'all';
    } elseif ($seasonParam !== 'this' && kbfSeasonRange($seasonParam) !== null) {
        $season = $seasonParam;
        $range = kbfSeasonRange($seasonParam);
    } else {
        $season = 'this';
        $range = kbfSeasonRange($current['key']);
    }

    // Filters other than the tab (the tab counts use them too)
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $like = '%' . kbfLikeEscape($q) . '%';
        $where[] = "(t.Name LIKE ? OR " . organiserNameSql('t', 'c') . " LIKE ? OR t.Venue LIKE ? OR t.Location LIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    if ($organiser !== '') {
        $where[] = "LOWER(TRIM(" . organiserNameSql('t', 'c') . ")) = ?";
        $params[] = organiserNameKey($organiser);
    }
    if ($range !== null) {
        $where[] = "DATE(t.TournamentDate) >= ? AND DATE(t.TournamentDate) < ?";
        $params[] = $range['from'];
        $params[] = $range['to'];
    }

    $derived = "(
        SELECT t.TournamentId, t.Name, t.TournamentDate, t.Venue, t.Location, t.Status, t.PosterPath,
               t.CreatedByAdminId, t.LastResultsReminderDate,
               " . organiserNameSql('t', 'c') . " AS OrganiserName,
               u.FirstName AS CreatedByFirstName, u.LastName AS CreatedByLastName,
               " . kbfTournamentStatusSql() . " AS AdminStatus
        FROM Tournaments t
        LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
        LEFT JOIN Users u ON t.CreatedByAdminId = u.UserId
        WHERE " . implode(' AND ', $where) . "
    ) x";

    // Tab counts
    $counts = array_fill_keys(ADMIN_TOURNAMENT_TABS, 0);
    $stmt = $pdo->prepare("SELECT x.AdminStatus, COUNT(*) AS n FROM $derived GROUP BY x.AdminStatus");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $n = (int) $row['n'];
        $counts['all'] += $n;
        $key = $row['AdminStatus'] === 'due_waiting' ? 'due' : $row['AdminStatus'];
        if (isset($counts[$key]) && $key !== 'all') {
            $counts[$key] += $n;
        }
    }

    $tabWhere = '';
    $tabParams = [];
    if ($tab === 'due') {
        $tabWhere = "WHERE x.AdminStatus IN ('due', 'due_waiting')";
    } elseif ($tab !== 'all') {
        $tabWhere = "WHERE x.AdminStatus = ?";
        $tabParams[] = $tab;
    }
    $order = in_array($tab, ['upcoming', 'due', 'waiting'], true)
        ? "x.TournamentDate ASC, x.TournamentId ASC"
        : "x.TournamentDate DESC, x.TournamentId DESC";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $derived $tabWhere");
    $stmt->execute(array_merge($params, $tabParams));
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT x.*,
               DATEDIFF(CURDATE(), DATE(x.TournamentDate)) AS DaysSince,
               (SELECT COUNT(*) FROM Matches m WHERE m.TournamentId = x.TournamentId AND m.IsApproved = 1) AS ResultsCount,
               (SELECT COUNT(*) FROM Matches m WHERE m.TournamentId = x.TournamentId) AS MatchCount,
               (SELECT COUNT(DISTINCT TRIM(COALESCE(m.GroupName, ''))) FROM Matches m WHERE m.TournamentId = x.TournamentId) AS CategoryCount,
               (SELECT AnnouncementId FROM Announcements WHERE TournamentId = x.TournamentId LIMIT 1) AS PostId
        FROM $derived
        $tabWhere
        ORDER BY $order
        LIMIT " . ADMIN_TOURNAMENT_PAGE_SIZE . " OFFSET " . (int) $offset);
    $stmt->execute(array_merge($params, $tabParams));
    $rows = $stmt->fetchAll();
    attachTournamentPosters($pdo, $rows);

    $manageAll = hasPermission('tournaments.manage_all');
    $tournaments = array_map(function ($r) use ($manageAll, $currentUserId) {
        $status = (string) $r['AdminStatus'];
        $isDue = in_array($status, ['due', 'due_waiting'], true);
        $date = substr((string) $r['TournamentDate'], 0, 10);
        $ts = strtotime($date);
        return [
            'id' => (int) $r['TournamentId'],
            'name' => (string) $r['Name'],
            'date' => $date,
            'weekday' => $ts ? date('l', $ts) : '',
            'organiser' => (string) $r['OrganiserName'],
            'venue' => (string) $r['Venue'],
            'town' => (string) $r['Location'],
            'poster' => $r['PosterUrl'],
            'storedStatus' => (string) $r['Status'],
            'status' => $status,
            'statusLabel' => KBF_TOURNAMENT_STATUS_LABELS[$status] ?? $status,
            'daysSince' => $isDue ? max(0, (int) $r['DaysSince']) : null,
            'resultsCount' => (int) $r['ResultsCount'],
            'matchCount' => (int) $r['MatchCount'],
            'categoryCount' => (int) $r['CategoryCount'],
            'lastReminder' => $r['LastResultsReminderDate'],
            'createdBy' => adminPersonName($r['CreatedByFirstName'], $r['CreatedByLastName']),
            'isOwn' => $currentUserId > 0 && (int) $r['CreatedByAdminId'] === (int) $currentUserId,
            'canDelete' => $manageAll && in_array($r['Status'], ['Pending', 'Rejected'], true) && (int) $r['MatchCount'] === 0,
        ];
    }, $rows);

    // Seasons that have tournaments, with the current one always listed
    $seasons = kbfSeasonsWithData($pdo, 'Tournaments', 'TournamentDate');
    if (!in_array($current['key'], array_column($seasons, 'key'), true)) {
        array_unshift($seasons, ['key' => $current['key'], 'name' => $current['name']]);
    }

    sendJSON([
        'success' => true,
        'tab' => $tab,
        'season' => $season,
        'currentSeason' => ['key' => $current['key'], 'name' => $current['name']],
        'seasons' => $seasons,
        'organiserNames' => array_slice(array_column(array_values(organiserNameGroups($pdo)), 'name'), 0, 200),
        'counts' => $counts,
        'total' => $total,
        'offset' => $offset,
        'pageSize' => ADMIN_TOURNAMENT_PAGE_SIZE,
        'tournaments' => $tournaments,
    ]);
}

// One tournament for the side panel: status, reminders, results and posts
function adminTournamentDetail(PDO $pdo) {
    $id = adminTournamentId();
    $stmt = $pdo->prepare("
        SELECT t.TournamentId, t.Name, t.TournamentDate, t.Status, t.CreatedByAdminId,
               t.LastResultsReminderDate, t.AutoResultsReminderDate,
               u.FirstName AS CreatedByFirstName, u.LastName AS CreatedByLastName,
               " . kbfTournamentStatusSql() . " AS AdminStatus,
               DATEDIFF(CURDATE(), DATE(t.TournamentDate)) AS DaysSince,
               (SELECT COUNT(*) FROM Matches m WHERE m.TournamentId = t.TournamentId AND m.IsApproved = 1) AS ResultsCount,
               (SELECT COUNT(*) FROM Matches m WHERE m.TournamentId = t.TournamentId) AS MatchCount,
               (SELECT COUNT(*) FROM Matches m WHERE m.TournamentId = t.TournamentId AND m.IsApproved = 0) AS WaitingCount
        FROM Tournaments t
        LEFT JOIN Users u ON t.CreatedByAdminId = u.UserId
        WHERE t.TournamentId = ?
    ");
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t) {
        sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
    }

    $stmt = $pdo->prepare("
        SELECT AnnouncementId, Title, IsPublished, IsVisible, CreatedDate, PublishedDate
        FROM Announcements
        WHERE TournamentId = ?
        ORDER BY CreatedDate DESC, AnnouncementId DESC
    ");
    $stmt->execute([$id]);
    $posts = array_map(function ($p) {
        return [
            'id' => (int) $p['AnnouncementId'],
            'title' => (string) $p['Title'],
            'published' => (int) $p['IsPublished'] === 1,
            'visible' => (int) $p['IsVisible'] === 1,
            'date' => $p['PublishedDate'] ?: $p['CreatedDate'],
        ];
    }, $stmt->fetchAll());

    $status = (string) $t['AdminStatus'];
    $date = substr((string) $t['TournamentDate'], 0, 10);
    $manageAll = hasPermission('tournaments.manage_all');
    sendJSON([
        'success' => true,
        'tournament' => [
            'id' => (int) $t['TournamentId'],
            'name' => (string) $t['Name'],
            'date' => $date,
            'storedStatus' => (string) $t['Status'],
            'status' => $status,
            'statusLabel' => KBF_TOURNAMENT_STATUS_LABELS[$status] ?? $status,
            'isPast' => $date !== '' && $date < date('Y-m-d'),
            'daysSince' => max(0, (int) $t['DaysSince']),
            'createdBy' => adminPersonName($t['CreatedByFirstName'], $t['CreatedByLastName']),
            'lastReminder' => $t['LastResultsReminderDate'],
            'autoReminder' => $t['AutoResultsReminderDate'],
            'resultsCount' => (int) $t['ResultsCount'],
            'waitingCount' => (int) $t['WaitingCount'],
            'canDelete' => $manageAll && in_array($t['Status'], ['Pending', 'Rejected'], true) && (int) $t['MatchCount'] === 0,
        ],
        'posts' => $posts,
    ]);
}

// The tournament's history: AuditLog entries about it, newest first
function adminTournamentHistory(PDO $pdo) {
    $id = adminTournamentId();
    $stmt = $pdo->prepare("
        SELECT a.AuditId, a.CreatedDate, a.ActorUserId, a.Action, a.Details,
               u.FirstName, u.LastName
        FROM AuditLog a
        LEFT JOIN Users u ON u.UserId = a.ActorUserId
        WHERE a.TargetType = 'tournament' AND a.TargetId = ?
        ORDER BY a.CreatedDate DESC, a.AuditId DESC
        LIMIT 200
    ");
    $stmt->execute([$id]);
    $entries = array_map(function ($r) {
        $who = (int) $r['ActorUserId'] === 0 ? 'Automatic' : adminPersonName($r['FirstName'], $r['LastName']);
        return [
            'id' => (int) $r['AuditId'],
            'when' => $r['CreatedDate'],
            'who' => $who !== '' ? $who : 'A former member of staff',
            'action' => kbfActionName($r['Action']),
            'details' => (string) $r['Details'],
        ];
    }, $stmt->fetchAll());
    sendJSON(['success' => true, 'history' => $entries]);
}

// ============================================
// ADMIN TOURNAMENTS PAGE (POST; login and CSRF are checked above)
// ============================================

// Remind the organiser: results_overdue to the tournament's creator, even
// when the event is switched off. Results due only, at most once in 24 hours.
function adminRemindOrganiser(PDO $pdo, array $data) {
    if (!hasAnyPermission(['tournaments.approve', 'tournaments.manage_all'])) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }
    $id = filter_var($data['tournamentId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
    }
    require_once __DIR__ . '/tournament-helpers.php';
    require_once __DIR__ . '/notify.php';

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT t.TournamentId, t.Name, t.TournamentDate, t.CreatedByAdminId, t.LastResultsReminderDate,
                   (t.LastResultsReminderDate IS NOT NULL AND t.LastResultsReminderDate > NOW() - INTERVAL 24 HOUR) AS SentRecently,
                   " . kbfTournamentStatusSql() . " AS AdminStatus
            FROM Tournaments t
            WHERE t.TournamentId = ?
            FOR UPDATE
        ");
        $stmt->execute([(int) $id]);
        $t = $stmt->fetch();
        if (!$t) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
        }
        if ($t['AdminStatus'] === 'due_waiting') {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Results for this tournament have been submitted and are waiting for approval, so no reminder is needed.'], 409);
        }
        if ($t['AdminStatus'] !== 'due') {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'A reminder can only be sent when a tournament\'s results are due.'], 409);
        }
        if ((int) $t['SentRecently'] === 1) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'A reminder was sent less than 24 hours ago. Please wait before sending another.'], 409);
        }
        $stmt = $pdo->prepare("UPDATE Tournaments SET LastResultsReminderDate = NOW() WHERE TournamentId = ?");
        $stmt->execute([(int) $id]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Results reminder error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }

    $results = notify('results_overdue', kbfResultsOverdueContext($pdo, $t, true));
    $status = $results['organiser'] ?? 'failed';
    $outcome = kbfNotifyOutcomeText($results, 'organiser', 'The reminder');

    if (!in_array($status, ['sent', 'waiting'], true)) {
        // Nothing went out: put the previous date back so it can be tried again
        try {
            $stmt = $pdo->prepare("UPDATE Tournaments SET LastResultsReminderDate = ? WHERE TournamentId = ?");
            $stmt->execute([$t['LastResultsReminderDate'], (int) $id]);
        } catch (PDOException $e) {
            error_log('Results reminder date restore error: ' . $e->getMessage());
        }
        sendJSON([
            'success' => false,
            'message' => $outcome !== '' ? $outcome : 'The reminder could not be sent.',
        ], 409);
    }

    auditLog('results_reminder_sent', 'tournament', (int) $id,
        'Reminder about missing results emailed to the organiser of ' . $t['Name'] . ($status === 'waiting' ? ' (waiting for the daily allowance)' : ''));

    $stmt = $pdo->prepare("SELECT LastResultsReminderDate FROM Tournaments WHERE TournamentId = ?");
    $stmt->execute([(int) $id]);
    sendJSON([
        'success' => true,
        'message' => $outcome !== '' ? $outcome : 'The reminder has been sent.',
        'lastReminder' => $stmt->fetchColumn() ?: null,
    ]);
}

// Change status (tournaments.manage_all): Approved, Completed or Cancelled.
// Approving a Pending tournament stays with the approval flow, and Completed
// follows approved results, as before.
function adminChangeStatus(PDO $pdo, array $data) {
    requirePermission('tournaments.manage_all');
    $id = filter_var($data['tournamentId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
    }
    $new = is_string($data['status'] ?? null) ? $data['status'] : '';
    if (!in_array($new, ['Approved', 'Completed', 'Cancelled'], true)) {
        sendJSON(['success' => false, 'message' => 'Choose Approved, Completed or Cancelled'], 400);
    }
    $reason = cleanTournamentMultiline($data['reason'] ?? '');
    if ($new === 'Cancelled' && $reason === '') {
        sendJSON(['success' => false, 'message' => 'Please give a reason for cancelling the tournament.'], 400);
    }
    if (tournamentTextLength($reason) > 500) {
        sendJSON(['success' => false, 'message' => 'The reason must be 500 characters or fewer.'], 400);
    }
    require_once __DIR__ . '/tournament-helpers.php';

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT t.TournamentId, t.Name, t.Status,
                   " . KBF_T_HAS_APPROVED_RESULTS . " AS HasApproved,
                   " . KBF_T_HAS_WAITING_RESULTS . " AS HasWaiting
            FROM Tournaments t
            WHERE t.TournamentId = ?
            FOR UPDATE
        ");
        $stmt->execute([(int) $id]);
        $t = $stmt->fetch();
        if (!$t) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
        }
        $old = (string) $t['Status'];
        $hasApproved = (int) $t['HasApproved'] === 1;
        $hasWaiting = (int) $t['HasWaiting'] === 1;

        $refuse = null;
        if ($old === $new) {
            $refuse = 'The tournament is already ' . $new . '.';
        } elseif ($old === 'Pending') {
            $refuse = 'This tournament is waiting approval. Approve or reject it first.';
        } elseif ($new === 'Approved' && $hasApproved) {
            $refuse = 'This tournament has approved results, so it stays Completed.';
        } elseif ($new === 'Completed' && !$hasApproved) {
            $refuse = 'A tournament becomes Completed when its results are approved. Submit or approve its results first.';
        } elseif ($new === 'Cancelled' && $hasApproved) {
            $refuse = 'This tournament has approved results and cannot be cancelled.';
        } elseif ($new === 'Cancelled' && $hasWaiting) {
            $refuse = 'Results for this tournament are waiting for approval. Approve or reject them first.';
        }
        if ($refuse !== null) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => $refuse], 409);
        }

        if ($new === 'Approved') {
            // Keep the original approver and date; record them when missing
            $stmt = $pdo->prepare("
                UPDATE Tournaments
                SET Status = 'Approved',
                    ApprovedByAdminId = COALESCE(ApprovedByAdminId, ?),
                    ApprovedDate = COALESCE(ApprovedDate, NOW())
                WHERE TournamentId = ?
            ");
            $stmt->execute([(int) $_SESSION['user_id'], (int) $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE Tournaments SET Status = ? WHERE TournamentId = ?");
            $stmt->execute([$new, (int) $id]);
        }

        // A database without 'Cancelled' in the Status list would not keep it
        $stmt = $pdo->prepare("SELECT Status FROM Tournaments WHERE TournamentId = ?");
        $stmt->execute([(int) $id]);
        if ((string) $stmt->fetchColumn() !== $new) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'The status could not be saved. Please run database/tournament-reminders.sql first.'], 409);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Tournament status change error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }

    auditLog('tournament_status_changed', 'tournament', (int) $id,
        'Status changed from ' . $old . ' to ' . $new . ($reason !== '' ? '. Reason: ' . $reason : ''));

    sendJSON(['success' => true, 'message' => 'Status changed to ' . $new . '.', 'status' => $new]);
}

// GET - Fetch tournaments
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $seeAll = canSeeAllTournaments();
    $seeOwn = canSeeOwnTournaments();
    $currentUserId = isLoggedIn() ? (int) $_SESSION['user_id'] : 0;

    try {
        // ============================================
        // ADMIN TOURNAMENTS PAGE
        // ============================================
        $getAction = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
        if (in_array($getAction, ['adminList', 'adminTournament', 'history'], true)) {
            if (!isLoggedIn()) {
                sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
            }
            if (!canSeeAllTournaments()) {
                sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
            require_once __DIR__ . '/tournament-helpers.php';
            require_once __DIR__ . '/report-helpers.php';

            if ($getAction === 'adminList') {
                adminTournamentList($pdo, $currentUserId);
            }
            if ($getAction === 'adminTournament') {
                adminTournamentDetail($pdo);
            }
            adminTournamentHistory($pdo);
        }

        if ($getAction === 'similar') {
            // Duplicate warning on the create form
            if (!isLoggedIn()) {
                sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
            }
            if (!hasAnyPermission(['tournaments.organise_own', 'tournaments.manage_all'])) {
                sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
            require_once __DIR__ . '/tournament-helpers.php';
            $name = is_string($_GET['name'] ?? null) ? $_GET['name'] : '';
            $date = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
            sendJSON([
                'success' => true,
                'similar' => kbfSimilarTournaments($pdo, function_exists('mb_substr') ? mb_substr($name, 0, 200, 'UTF-8') : substr($name, 0, 200), $date, $currentUserId, canSeeAllTournaments()),
            ]);
        }

        if (isset($_GET['lastOrganiser'])) {
            // Pre-fill for the create form: the OrganiserName this user used last
            if (!isLoggedIn()) {
                sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
            }
            if (!hasAnyPermission(['tournaments.organise_own', 'tournaments.manage_all'])) {
                sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
            $stmt = $pdo->prepare("
                SELECT " . organiserNameSql('t', 'c') . " AS OrganiserName
                FROM Tournaments t
                LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
                WHERE t.CreatedByAdminId = ?
                ORDER BY t.CreatedDate DESC, t.TournamentId DESC
                LIMIT 1
            ");
            $stmt->execute([$currentUserId]);
            $last = $stmt->fetch();
            sendJSON([
                'success' => true,
                'lastOrganiserName' => $last ? $last['OrganiserName'] : null
            ]);
        }

        if (isset($_GET['organiserNames'])) {
            // Suggestions for the Organiser field: distinct names, most used first
            if (!isLoggedIn()) {
                sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
            }
            if (!hasAnyPermission(['tournaments.organise_own', 'tournaments.manage_all'])) {
                sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
            }
            $names = array_slice(array_column(array_values(organiserNameGroups($pdo)), 'name'), 0, 200);
            sendJSON(['success' => true, 'organiserNames' => $names]);
        }

        if (isset($_GET['id'])) {
            // Validate ID
            $tournamentId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            if (!$tournamentId) {
                sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
            }

            // Get single tournament with details
            $stmt = $pdo->prepare("
    SELECT t.TournamentId, t.Name, t.TournamentDate, t.Venue, t.Location, t.PostCode,
           t.Status, t.CreatedDate, t.ApprovedDate, t.CategoriesOffered,
           " . TOURNAMENT_EXTRA_COLUMNS . ",
           " . organiserNameSql('t', 'c') . " as ClubName,
           " . organiserNameSql('t', 'c') . " as OrganiserName,
           c.ClubId, t.CreatedByAdminId,
           (SELECT AnnouncementId FROM Announcements WHERE TournamentId = t.TournamentId LIMIT 1) as PostId,
           u1.FirstName as CreatedByFirstName, u1.LastName as CreatedByLastName,
           u2.FirstName as ApprovedByFirstName, u2.LastName as ApprovedByLastName
    FROM Tournaments t
    LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
    LEFT JOIN Users u1 ON t.CreatedByAdminId = u1.UserId
    LEFT JOIN Users u2 ON t.ApprovedByAdminId = u2.UserId
    WHERE t.TournamentId = ?
");
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch();

            if (!$tournament) {
                sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
            }

            // PRIVACY: Pending/Rejected/Cancelled only for approvers and managers, or
            // the organiser who created it. Admin names only for approvers and managers.
            $isOwn = $currentUserId > 0 && (int) $tournament['CreatedByAdminId'] === $currentUserId;
            if (!$seeAll) {
                if (in_array($tournament['Status'], ['Pending', 'Rejected', 'Cancelled'], true) && !($seeOwn && $isOwn)) {
                    sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
                }
                unset(
                    $tournament['CreatedByFirstName'], $tournament['CreatedByLastName'],
                    $tournament['ApprovedByFirstName'], $tournament['ApprovedByLastName']
                );
            }
            unset($tournament['CreatedByAdminId']);
            $tournament['IsOwn'] = $isOwn;

            $rows = [$tournament];
            attachTournamentPosters($pdo, $rows);
            $tournament = $rows[0];

            // Get matches
           $stmt = $pdo->prepare("
    SELECT m.MatchId, m.RoundType, m.WinnerTeam, m.Team1Score, m.Team2Score,
           m.MatchDate, m.GroupName,
           m.GuestPlayer1Name, m.GuestPartner1Name, m.GuestPlayer2Name, m.GuestPartner2Name,
           m.Player1Id, m.Partner1Id, m.Player2Id, m.Partner2Id,
           cat.Name as CategoryName, cat.CategoryId,
           p1.FirstName as P1FirstName, p1.LastName as P1LastName, p1.PlayerId as P1PlayerId,
           c1.ShortName as P1Category,
           p2.FirstName as Partner1FirstName, p2.LastName as Partner1LastName, p2.PlayerId as Partner1PlayerId,
           c2.ShortName as Partner1Category,
           p3.FirstName as P2FirstName, p3.LastName as P2LastName, p3.PlayerId as P2PlayerId,
           c3.ShortName as P2Category,
           p4.FirstName as Partner2FirstName, p4.LastName as Partner2LastName, p4.PlayerId as Partner2PlayerId,
           c4.ShortName as Partner2Category,
           COALESCE(p1.IsRemoved, 0) as P1Removed, COALESCE(p2.IsRemoved, 0) as Partner1Removed,
           COALESCE(p3.IsRemoved, 0) as P2Removed, COALESCE(p4.IsRemoved, 0) as Partner2Removed,
           (COALESCE(p1.IsActive, 1) = 0) as P1Inactive, (COALESCE(p2.IsActive, 1) = 0) as Partner1Inactive,
           (COALESCE(p3.IsActive, 1) = 0) as P2Inactive, (COALESCE(p4.IsActive, 1) = 0) as Partner2Inactive
    FROM Matches m
    LEFT JOIN Categories cat ON m.CategoryId = cat.CategoryId
    LEFT JOIN Users p1 ON m.Player1Id = p1.UserId
    LEFT JOIN Categories c1 ON p1.CategoryId = c1.CategoryId
    LEFT JOIN Users p2 ON m.Partner1Id = p2.UserId
    LEFT JOIN Categories c2 ON p2.CategoryId = c2.CategoryId
    LEFT JOIN Users p3 ON m.Player2Id = p3.UserId
    LEFT JOIN Categories c3 ON p3.CategoryId = c3.CategoryId
    LEFT JOIN Users p4 ON m.Partner2Id = p4.UserId
    LEFT JOIN Categories c4 ON p4.CategoryId = c4.CategoryId
    WHERE m.TournamentId = ? AND m.IsApproved = 1
    ORDER BY FIELD(m.RoundType, 'Final', 'SemiFinal', 'QuarterFinal'),
         m.MatchId ASC
");
            $stmt->execute([$tournamentId]);
            // Removed players read "Former member"; staff keep the user ids
            // so results can still be edited
            $matches = kbfMaskRemovedInMatches($stmt->fetchAll(), isLoggedIn() && isStaff());

            sendJSON([
    'success' => true,
    'tournament' => $tournament,
    'matches' => $matches,
    'roundPoints' => getRoundPointsMap($pdo)
]);

        } else {
            // Get all tournaments with filters
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            $clubId = isset($_GET['clubId']) ? $_GET['clubId'] : null;
            $mineOnly = isset($_GET['mine']) && $_GET['mine'] === '1';

            // Validate status
            if ($status && !in_array($status, ['upcoming', 'past', 'listed', 'pending', 'Pending', 'Approved', 'Rejected', 'Completed'])) {
                sendJSON(['success' => false, 'message' => 'Invalid status'], 400);
            }

            // Optional date range (YYYY-MM-DD, both inclusive), e.g. one
            // calendar month on the Tournaments page
            $dateRange = [];
            foreach (['from', 'to'] as $key) {
                if (!isset($_GET[$key]) || $_GET[$key] === '') {
                    continue;
                }
                $value = (string) $_GET[$key];
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    sendJSON(['success' => false, 'message' => 'Invalid date'], 400);
                }
                $dateRange[$key] = $value;
            }

            // Validate clubId
            if ($clubId) {
                $clubId = filter_var($clubId, FILTER_VALIDATE_INT);
                if (!$clubId) {
                    sendJSON(['success' => false, 'message' => 'Invalid club ID'], 400);
                }
            }

            $query = "
    SELECT t.TournamentId, t.Name, t.TournamentDate, t.Venue, t.Location, t.Status, t.CreatedDate, t.ApprovedDate,
           " . TOURNAMENT_EXTRA_COLUMNS . ",
           " . organiserNameSql('t', 'c') . " as ClubName,
           " . organiserNameSql('t', 'c') . " as OrganiserName,
           c.ClubId as OrganizingClubId,
           (CASE WHEN t.CreatedByAdminId = ? THEN 1 ELSE 0 END) as IsOwn,
           (SELECT COUNT(*) FROM Matches WHERE TournamentId = t.TournamentId) as MatchCount,
           (SELECT AnnouncementId FROM Announcements WHERE TournamentId = t.TournamentId LIMIT 1) as PostId
    FROM Tournaments t
    LEFT JOIN Clubs c ON t.OrganizingClubId = c.ClubId
    WHERE 1=1
";

            $params = [$currentUserId];

            // PRIVACY: Pending, Rejected and Cancelled only for approvers and
            // managers; organisers also see their own
            if (!$seeAll) {
                if ($seeOwn && $currentUserId > 0) {
                    $query .= " AND (t.Status NOT IN ('Pending', 'Rejected', 'Cancelled') OR t.CreatedByAdminId = ?)";
                    $params[] = $currentUserId;
                } else {
                    $query .= " AND t.Status NOT IN ('Pending', 'Rejected', 'Cancelled')";
                }
            }

            // Only the tournaments this user created
            if ($mineOnly) {
                $query .= " AND t.CreatedByAdminId = ?";
                $params[] = $currentUserId;
            }

            if ($status === 'upcoming') {
    $query .= " AND t.TournamentDate >= CURDATE() AND t.Status = 'Approved'";
} elseif ($status === 'past') {
    $query .= " AND t.TournamentDate < CURDATE() AND t.Status IN ('Completed', 'Approved')";
} elseif ($status === 'listed') {
    // Upcoming and past together (the Tournaments page calendar)
    $query .= " AND ((t.TournamentDate >= CURDATE() AND t.Status = 'Approved')
                  OR (t.TournamentDate < CURDATE() AND t.Status IN ('Completed', 'Approved')))";
} elseif ($status === 'pending') {
    $query .= " AND t.Status = 'Pending'";
} elseif ($status) {
    $query .= " AND t.Status = ?";
    $params[] = $status;
}

if ($clubId) {
    $query .= " AND t.OrganizingClubId = ?";
    $params[] = $clubId;
}
if (isset($dateRange['from'])) {
    $query .= " AND t.TournamentDate >= ?";
    $params[] = $dateRange['from'];
}
if (isset($dateRange['to'])) {
    $query .= " AND t.TournamentDate <= ?";
    $params[] = $dateRange['to'];
}

// Paging (PERF-06): ?limit= (1 to 100) and ?offset= return one page with
// total and hasMore; without limit the whole list is returned as before
$pageLimit = null;
$pageOffset = 0;
if (isset($_GET['limit'])) {
    $pageLimit = filter_var($_GET['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $pageOffset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
    if ($pageLimit === false || $pageOffset === false) {
        sendJSON(['success' => false, 'message' => 'Invalid limit or offset'], 400);
    }
}
$countSql = "SELECT COUNT(*) " . substr($query, strpos($query, 'FROM Tournaments t'));
$countParams = array_slice($params, 1);

// Sort upcoming tournaments by soonest first (ASC), past tournaments by latest first (DESC)
if ($status === 'upcoming') {
    $query .= " ORDER BY t.TournamentDate ASC, (t.StartTime IS NULL) ASC, t.StartTime ASC, t.TournamentId ASC";
} else {
    $query .= " ORDER BY t.TournamentDate DESC, (t.StartTime IS NULL) DESC, t.StartTime DESC, t.TournamentId DESC";
}

            if ($pageLimit !== null) {
                $stmt = $pdo->prepare($query . " LIMIT ? OFFSET ?");
                $i = 1;
                foreach ($params as $p) {
                    $stmt->bindValue($i++, $p);
                }
                $stmt->bindValue($i++, (int) $pageLimit, PDO::PARAM_INT);
                $stmt->bindValue($i, (int) $pageOffset, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
            }
            $tournaments = $stmt->fetchAll();

            attachTournamentPosters($pdo, $tournaments);

            $response = [
                'success' => true,
                'tournaments' => $tournaments
            ];
            if ($pageLimit !== null) {
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($countParams);
                $total = (int) $countStmt->fetchColumn();
                $response['total'] = $total;
                $response['offset'] = (int) $pageOffset;
                $response['limit'] = (int) $pageLimit;
                $response['hasMore'] = $pageOffset + count($tournaments) < $total;
            }
            // Public lists (no one signed in, not "mine") can be cached briefly
            if (!$mineOnly && !in_array($status, ['pending', 'Pending', 'Rejected'], true)) {
                kbfPublicCache(60);
            }
            sendJSON($response);
        }
    } catch(PDOException $e) {
        error_log('Database error in tournaments.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// POST - Create tournament, or update its details
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Admin Tournaments page actions (JSON): own permission checks
    $adminContentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($adminContentType, 'multipart/form-data') !== 0) {
        $adminData = json_decode(file_get_contents('php://input'), true);
        $adminAction = is_array($adminData) && is_string($adminData['action'] ?? null) ? $adminData['action'] : '';
        if ($adminAction === 'remindOrganiser') {
            adminRemindOrganiser($pdo, $adminData);
        }
        if ($adminAction === 'changeStatus') {
            adminChangeStatus($pdo, $adminData);
        }
    }

    if (!hasAnyPermission(['tournaments.organise_own', 'tournaments.manage_all'])) {
        sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
    }

    // Multipart (poster attached) or JSON body
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = stripos($contentType, 'multipart/form-data') === 0;

    if ($isMultipart) {
        // A request bigger than post_max_size arrives with empty $_POST and $_FILES
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            sendJSON(['success' => false, 'message' => tournamentPosterTooBigMessage()], 400);
        }
        $data = $_POST;
    } else {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
    }

    kbfRequireScalarFields($data);
    if (!is_array($data)) {
        sendJSON(['success' => false, 'message' => 'Invalid request'], 400);
    }

    $action = isset($data['action']) && is_string($data['action']) ? $data['action'] : 'create';

    // ============================================
    // UPDATE DETAILS - poster, registration, contact and description only
    // ============================================
    if ($action === 'updateDetails') {
        $tournamentId = filter_var($data['tournamentId'] ?? null, FILTER_VALIDATE_INT);
        if (!$tournamentId || $tournamentId < 1) {
            sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
        }

        $newPosterPath = null;
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT TournamentId, Name, CreatedByAdminId, Status, TournamentDate, PosterPath
                FROM Tournaments
                WHERE TournamentId = ?
                FOR UPDATE
            ");
            $stmt->execute([$tournamentId]);
            $existing = $stmt->fetch();

            if (!$existing) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
            }

            // OWNERSHIP: tournaments.manage_all edits any tournament;
            // tournaments.organise_own only their own, while Pending or Approved
            if (!hasPermission('tournaments.manage_all')) {
                if (!hasPermission('tournaments.organise_own') || !isOwnTournamentRow($existing)) {
                    $pdo->rollBack();
                    sendJSON(['success' => false, 'message' => 'You can only edit tournaments you created'], 403);
                }

                if (!in_array($existing['Status'], ['Pending', 'Approved'], true)) {
                    $pdo->rollBack();
                    sendJSON(['success' => false, 'message' => 'Details can only be changed while a tournament is Pending or Approved'], 409);
                }
            }

            list($values, $error) = readTournamentExtras($data, $existing['TournamentDate'], true);
            if ($error !== null) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => $error], 400);
            }

            $removePoster = isset($data['removePoster']) && $data['removePoster'] === '1';
            if (hasPosterUpload()) {
                list($newPosterPath, $error) = storeTournamentPoster($_FILES['poster']);
                if ($error !== null) {
                    $pdo->rollBack();
                    sendJSON(['success' => false, 'message' => $error], 400);
                }
                $values['PosterPath'] = $newPosterPath;
            } elseif ($removePoster) {
                $values['PosterPath'] = null;
            }

            if (empty($values)) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'No fields to update'], 400);
            }

            // Status, approval and the core fields are deliberately never touched here
            $sets = [];
            $params = [];
            foreach ($values as $column => $value) {
                $sets[] = "$column = ?";
                $params[] = $value;
            }
            $params[] = $tournamentId;
            $stmt = $pdo->prepare("UPDATE Tournaments SET " . implode(', ', $sets) . " WHERE TournamentId = ?");
            $stmt->execute($params);

            $pdo->commit();

            auditLog('tournament_edited', 'tournament', $tournamentId,
                'Details updated for: ' . $existing['Name'] . '; fields: ' . implode(', ', array_keys($values)));

            // Poster replaced or removed: delete the old file (own posters only)
            $oldPosterPath = $existing['PosterPath'];
            if (array_key_exists('PosterPath', $values) && $oldPosterPath && $oldPosterPath !== $values['PosterPath']) {
                deleteTournamentPoster($oldPosterPath);
            }

            sendJSON([
                'success' => true,
                'message' => 'Tournament details updated',
                'posterPath' => array_key_exists('PosterPath', $values) ? $values['PosterPath'] : $oldPosterPath
            ]);

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newPosterPath) {
                deleteTournamentPoster($newPosterPath);
            }
            error_log('Tournament details update error: ' . $e->getMessage());
            sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
        }
    }

    if ($action !== 'create') {
        sendJSON(['success' => false, 'message' => 'Invalid action'], 400);
    }

    // ============================================
    // CREATE
    // ============================================
    $required = ['name', 'tournamentDate', 'venue', 'location', 'organiserName', 'categoriesOffered'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendJSON(['success' => false, 'message' => ucfirst($field) . ' is required'], 400);
        }
    }

    // SECURITY: Validate inputs (tournament names never hold < or >)
    $name = trim(str_replace(['<', '>'], '', (string) $data['name']));
$venue = trim($data['venue']);
$location = trim($data['location']);
$postCode = isset($data['postCode']) ? trim($data['postCode']) : null;

    // Characters, not bytes (accented letters count once)
    if (kbfTextLength($name) < 3 || kbfTextLength($name) > 200) {
        sendJSON(['success' => false, 'message' => 'Tournament name must be between 3 and 200 characters'], 400);
    }

    // Validate date format
    $tournamentDate = $data['tournamentDate'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tournamentDate)) {
        sendJSON(['success' => false, 'message' => 'Invalid date format'], 400);
    }

    // Organiser: free text (no club is required or set)
    list($organiserName, $organiserError) = cleanOrganiserName($data['organiserName']);
    if ($organiserError !== null) {
        sendJSON(['success' => false, 'message' => $organiserError], 400);
    }
    // Same name apart from upper and lower case: keep the existing spelling
    $organiserName = canonicalOrganiserName($pdo, $organiserName);

    // Validate categories offered (free text)
if (!isset($data['categoriesOffered']) || empty(trim($data['categoriesOffered']))) {
    sendJSON(['success' => false, 'message' => 'Categories offered required'], 400);
}

$categoriesOffered = trim($data['categoriesOffered']);
if (strlen($categoriesOffered) > 100) {
    sendJSON(['success' => false, 'message' => 'Categories text too long (max 100 characters)'], 400);
}

    // Optional details (all may be empty)
    list($extras, $extrasError) = readTournamentExtras($data, $tournamentDate, false);
    if ($extrasError !== null) {
        sendJSON(['success' => false, 'message' => $extrasError], 400);
    }

    // A repeat of the same one-time request id (double tap, retry) gets the
    // first answer back; no second tournament is created
    require_once __DIR__ . '/request-ids.php';
    kbfRequestIdBegin($pdo, 'tournament', is_string($data['requestId'] ?? null) ? $data['requestId'] : null);

    $posterPath = null;
    try {
        $pdo->beginTransaction();

        // Created by someone with tournaments.approve: saved as Approved, with
        // the approver and approval date recorded as approvals.php does
        $autoApprove = hasPermission('tournaments.approve');

        // Optional poster
        if (hasPosterUpload()) {
            list($posterPath, $posterError) = storeTournamentPoster($_FILES['poster']);
            if ($posterError !== null) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => $posterError], 400);
            }
        }

        // Insert tournament
        $stmt = $pdo->prepare("
    INSERT INTO Tournaments (Name, TournamentDate, Venue, Location, PostCode, CategoriesOffered, OrganizingClubId, OrganiserName,
                             Status, CreatedByAdminId, ApprovedByAdminId, ApprovedDate,
                             PosterPath, RegistrationUrl, RegistrationCloseDate, EntryFee, StartTime, Description, ContactName, ContactPhone)
    VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, " . ($autoApprove ? "NOW()" : "NULL") . ", ?, ?, ?, ?, ?, ?, ?, ?)
");
        $stmt->execute([
    $name,
    $tournamentDate,
    $venue,
    $location,
    $postCode,
    $categoriesOffered,
    $organiserName,
    $autoApprove ? 'Approved' : 'Pending',
    $_SESSION['user_id'],
    $autoApprove ? $_SESSION['user_id'] : null,
    $posterPath,
    $extras['RegistrationUrl'],
    $extras['RegistrationCloseDate'],
    $extras['EntryFee'],
    $extras['StartTime'],
    $extras['Description'],
    $extras['ContactName'],
    $extras['ContactPhone']
]);

        $newTournamentId = $pdo->lastInsertId();

        if ($autoApprove) {
            // Same approval history row as approvals.php writes
            $stmt = $pdo->prepare("
                INSERT INTO ApprovalLogs
                (LogType, Action, ReferenceId, ReferenceName, ReferenceDetails, ApprovedByAdminId, ApprovedByAdminName, Comments)
                VALUES ('Tournament', 'Approved', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $newTournamentId,
                $name,
                "Tournament: {$name}, Date: " . date('d M Y', strtotime($tournamentDate)) . ", Venue: {$venue}",
                $_SESSION['user_id'],
                $_SESSION['name'] ?? '',
                'Approved when created'
            ]);
        }

$pdo->commit();

auditLog('tournament_created', 'tournament', $newTournamentId,
    'Tournament: ' . $name . '; organiser: ' . $organiserName . '; status: ' . ($autoApprove ? 'Approved' : 'Pending'));

// Submitted for approval: tell the approvers (after the commit; a problem
// never affects the new tournament)
if (!$autoApprove) {
    require_once __DIR__ . '/notify.php';
    notify('tournament_submitted', [
        'values' => [
            'tournament_name' => html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'),
            'tournament_date' => date('l, d F Y', strtotime($tournamentDate)),
            'organiser_name' => html_entity_decode((string) $organiserName, ENT_QUOTES, 'UTF-8'),
        ],
        'relatedType' => 'tournament',
        'relatedId' => (int) $newTournamentId,
    ]);
}

sendJSON([
    'success' => true,
    'message' => $autoApprove ? 'Tournament created and approved.' : 'Tournament created successfully. Pending approval.',
    'tournamentId' => $newTournamentId,
    'status' => $autoApprove ? 'Approved' : 'Pending',
    'organiserName' => $organiserName
]);

    } catch(PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($posterPath) {
            deleteTournamentPoster($posterPath);
        }
        error_log('Tournament creation error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// PUT - Update tournament
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['tournamentId'])) {
        sendJSON(['success' => false, 'message' => 'Tournament ID required'], 400);
    }
    kbfRequireScalarFields($data);

    // Validate tournament ID
    $tournamentId = filter_var($data['tournamentId'], FILTER_VALIDATE_INT);
    if (!$tournamentId) {
        sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
    }

    // The old "approve" and "status" writes are gone: approving or rejecting
    // goes through api/approvals.php and status changes through the
    // changeStatus action (adminChangeStatus), which keep every rule
    if (isset($data['approve']) || isset($data['status'])) {
        sendJSON(['success' => false, 'message' => 'This action is no longer available. Use the Approvals page or Change status.'], 410);
    }

    try {
        {
            // Core fields need tournaments.manage_all
            $coreFields = ['name', 'tournamentDate', 'venue', 'location', 'postCode', 'categoriesOffered', 'organiserName'];
            foreach ($coreFields as $field) {
                if (isset($data[$field]) && !hasPermission('tournaments.manage_all')) {
                    sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
                }
            }

            // Update tournament details
            $updates = [];
            $params = [];

            if (isset($data['name'])) {
    // Tournament names never hold < or >
    $name = trim(str_replace(['<', '>'], '', (string) $data['name']));
    if (kbfTextLength($name) < 3 || kbfTextLength($name) > 200) {
        sendJSON(['success' => false, 'message' => 'Tournament name must be between 3 and 200 characters'], 400);
    }
    $updates[] = "Name = ?";
    $params[] = $name;
}

if (isset($data['tournamentDate'])) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['tournamentDate'])) {
        sendJSON(['success' => false, 'message' => 'Invalid date format'], 400);
    }
    $updates[] = "TournamentDate = ?";
    $params[] = $data['tournamentDate'];
}

if (isset($data['venue'])) {
    $updates[] = "Venue = ?";
    $params[] = trim($data['venue']);
}

if (isset($data['location'])) {
    $updates[] = "Location = ?";
    $params[] = trim($data['location']);
}

if (isset($data['postCode'])) {
    $postCode = cleanTournamentLine($data['postCode']);
    if (strlen($postCode) > 20) {
        sendJSON(['success' => false, 'message' => 'Postcode must be 20 characters or fewer'], 400);
    }
    $updates[] = "PostCode = ?";
    $params[] = $postCode === '' ? null : $postCode;
}

if (isset($data['categoriesOffered'])) {
    $categoriesOffered = trim((string) $data['categoriesOffered']);
    if ($categoriesOffered === '' || strlen($categoriesOffered) > 100) {
        sendJSON(['success' => false, 'message' => 'Categories offered required (max 100 characters)'], 400);
    }
    $updates[] = "CategoriesOffered = ?";
    $params[] = $categoriesOffered;
}

if (isset($data['organiserName'])) {
    list($organiserName, $organiserError) = cleanOrganiserName($data['organiserName']);
    if ($organiserError !== null) {
        sendJSON(['success' => false, 'message' => $organiserError], 400);
    }
    // Same name apart from upper and lower case: keep the existing spelling
    $organiserName = canonicalOrganiserName($pdo, $organiserName, $tournamentId);
    $updates[] = "OrganiserName = ?";
    $params[] = $organiserName;
}

            if (empty($updates)) {
                sendJSON(['success' => false, 'message' => 'No fields to update'], 400);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT Name, Status, TournamentDate FROM Tournaments WHERE TournamentId = ? FOR UPDATE");
            $stmt->execute([$tournamentId]);
            $before = $stmt->fetch();
            if (!$before) {
                $pdo->rollBack();
                sendJSON(['success' => false, 'message' => 'Tournament not found or no changes made'], 404);
            }

            $params[] = $tournamentId;
            $query = "UPDATE Tournaments SET " . implode(", ", $updates) . " WHERE TournamentId = ?";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            // The tournament exists (read above). No changed row means the
            // values were already the same (for example an organiser name
            // that only differs in case): that is a success, not "not found"
            if ($stmt->rowCount() === 0) {
                $pdo->commit();
                sendJSON(['success' => true, 'message' => 'Nothing to change']);
            }

            // Date of an approved or completed tournament changed: check each
            // award again against the career-only rule for the new date and
            // move points into or out of season points (api/points-rules.php)
            $recheck = null;
            $dateChanged = isset($data['tournamentDate'])
                && substr((string) $before['TournamentDate'], 0, 10) !== $data['tournamentDate'];
            if ($dateChanged && in_array($before['Status'], ['Approved', 'Completed'], true)) {
                require_once __DIR__ . '/points-rules.php';
                $recheck = kbfRecheckTournamentAwards($pdo, $tournamentId);
            }

            $pdo->commit();

            $changed = [];
            foreach ($updates as $statement) {
                $changed[] = trim(explode('=', $statement)[0]);
            }
            if (!empty($changed)) {
                auditLog('tournament_edited', 'tournament', $tournamentId, 'Fields: ' . implode(', ', $changed));
            }
            if ($recheck !== null) {
                auditLog('tournament_points_rechecked', 'tournament', $tournamentId,
                    'Date ' . substr((string) $before['TournamentDate'], 0, 10) . ' -> ' . $data['tournamentDate']
                    . ' for ' . $before['Name'] . ': ' . $recheck['in'] . ' award(s) moved into season points, '
                    . $recheck['out'] . ' moved to career points only');
                if ($recheck['players']) {
                    kbfRecalculateEligibility($pdo, $recheck['players']);
                }
            }

            sendJSON(['success' => true, 'message' => 'Tournament updated successfully']);
        }
    } catch(PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Tournament update error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// DELETE - Delete tournament: tournaments.manage_all, only while Pending or
// Rejected and with no matches
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requirePermission('tournaments.manage_all');

    if (!isset($_GET['id'])) {
        sendJSON(['success' => false, 'message' => 'Tournament ID required'], 400);
    }

    $tournamentId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if (!$tournamentId) {
        sendJSON(['success' => false, 'message' => 'Invalid tournament ID'], 400);
    }

    try {
        $pdo->beginTransaction();

        // Remember the poster so its file can be removed after the delete
        $stmt = $pdo->prepare("SELECT Name, Status, PosterPath FROM Tournaments WHERE TournamentId = ? FOR UPDATE");
        $stmt->execute([$tournamentId]);
        $posterRow = $stmt->fetch();

        if (!$posterRow) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
        }

        if (!in_array($posterRow['Status'], ['Pending', 'Rejected'], true)) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Only Pending or Rejected tournaments can be deleted'], 409);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM Matches WHERE TournamentId = ?");
        $stmt->execute([$tournamentId]);
        if ((int) $stmt->fetch()['cnt'] > 0) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Tournaments with results cannot be deleted'], 409);
        }

        // Gallery photo files are removed after the commit (QUAL-12)
        $stmt = $pdo->prepare("SELECT FilePath FROM GalleryPhotos WHERE TournamentId = ?");
        $stmt->execute([$tournamentId]);
        $galleryFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete related records first. TournamentCategories and
        // TournamentPhotos are old tables that still exist on the live
        // database (base-schema-live.sql); GalleryPhotos would also go by its
        // ON DELETE CASCADE.
        $stmt = $pdo->prepare("DELETE FROM GalleryPhotos WHERE TournamentId = ?");
        $stmt->execute([$tournamentId]);

        $stmt = $pdo->prepare("DELETE FROM TournamentCategories WHERE TournamentId = ?");
        $stmt->execute([$tournamentId]);

        $stmt = $pdo->prepare("DELETE FROM Matches WHERE TournamentId = ?");
        $stmt->execute([$tournamentId]);

        $stmt = $pdo->prepare("DELETE FROM ResultsApprovalQueue WHERE TournamentId = ?");
        $stmt->execute([$tournamentId]);

        $stmt = $pdo->prepare("DELETE FROM TournamentPhotos WHERE TournamentId = ?");
        $stmt->execute([$tournamentId]);

        // Delete tournament
        $stmt = $pdo->prepare("DELETE FROM Tournaments WHERE TournamentId = ?");
        $stmt->execute([$tournamentId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            sendJSON(['success' => false, 'message' => 'Tournament not found'], 404);
        }

        $pdo->commit();

        auditLog('tournament_deleted', 'tournament', $tournamentId,
            'Tournament: ' . $posterRow['Name'] . '; status was ' . $posterRow['Status']);

        if ($posterRow && !empty($posterRow['PosterPath'])) {
            deleteTournamentPoster($posterRow['PosterPath']);
        }

        // Gallery photos, their display versions and thumbnails, then the
        // tournament's own gallery folder (uploads/gallery/<id>/) if empty
        require_once __DIR__ . '/image-helpers.php';
        $galleryUrlDir = '/uploads/gallery/' . (int) $tournamentId;
        foreach ($galleryFiles as $galleryPath) {
            kbfDeleteStoredImage((string) $galleryPath, $galleryUrlDir);
        }
        $galleryDir = dirname(__DIR__) . $galleryUrlDir;
        if (is_dir($galleryDir)) {
            foreach (scandir($galleryDir) ?: [] as $name) {
                // Anything left there belonged only to this tournament
                if (preg_match('/^[A-Za-z0-9_\-]+(\.(d|t))?\.(jpe?g|png|webp|gif)$/i', $name) && is_file($galleryDir . '/' . $name)) {
                    @unlink($galleryDir . '/' . $name);
                }
            }
            @rmdir($galleryDir);
        }

        sendJSON(['success' => true, 'message' => 'Tournament deleted successfully']);
    } catch(PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Tournament deletion error: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
?>
