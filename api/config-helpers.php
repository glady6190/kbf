<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
/**
 * Shared configuration helpers.
 *
 * Reads runtime settings and round-point values from the database, with safe
 * fallbacks so a missing/empty table can never break scoring.
 *
 * These functions take $pdo as a parameter. Callers already do
 * `require_once 'config.php';` (which creates the global $pdo) before using
 * them — the same pattern the rest of api/ follows.
 */

// ============================================
// SHARED BUSINESS CONSTANTS (one place each)
// ============================================

// KBF IDs are this prefix plus a number of at least 3 digits (KBFUK001)
const KBF_ID_PREFIX = 'KBFUK';

// Player photos are scaled to fit inside this square (pixels)
const KBF_PROFILE_PHOTO_SIZE = 500;

// Points per placing: which RoundPoints rows each placing is stored in, and
// the historical values used when a row is missing (getRoundPointsMap,
// kbfPlacingPoints in api/grading.php)
const KBF_PLACING_ROWS = [
    // placing => [[RoundType, Outcome, Reason], ...]
    'winners' => [['Final', 'Winner', 'Winner']],
    'runnersUp' => [['Final', 'Loser', 'Runner-up']],
    'semiFinalists' => [['SemiFinal', 'Winner', 'Semi-Finalist'], ['SemiFinal', 'Loser', 'Semi-Finalist']],
    'quarterFinalists' => [['QuarterFinal', 'Winner', 'Quarter-Finalist'], ['QuarterFinal', 'Loser', 'Quarter-Finalist']],
];
const KBF_PLACING_DEFAULTS = ['winners' => 100, 'runnersUp' => 75, 'semiFinalists' => 50, 'quarterFinalists' => 30];

/**
 * Fetch a single value from the Settings table.
 *
 * @param PDO    $pdo
 * @param string $key      SettingKey to look up
 * @param mixed  $default  returned if the key is missing or the query fails
 * @return mixed           the stored SettingValue, or $default
 */
function getSetting($pdo, $key, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT SettingValue FROM Settings WHERE SettingKey = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row === false || !isset($row['SettingValue'])) {
            return $default;
        }
        return $row['SettingValue'];
    } catch (PDOException $e) {
        error_log('getSetting failed for key "' . $key . '": ' . $e->getMessage());
        return $default;
    }
}

/**
 * Read a setting with a default, cached for the rest of the request.
 * All Settings rows are loaded with one query the first time; later calls
 * cost nothing. A missing key (or a missing table) returns $default.
 * Call kbfSettingsForget() after changing a setting in the same request.
 * Jobs that change a setting and read it back use getSetting() instead.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function kbfSetting($key, $default = null) {
    $all = kbfSettingsCache();
    return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
}

// Whole number setting kept between $min and $max (today's value as default)
function kbfSettingInt($key, $default, $min = null, $max = null) {
    $raw = kbfSetting($key, null);
    if ($raw === null || !preg_match('/^\s*-?\d+\s*$/', (string) $raw)) {
        return (int) $default;
    }
    $value = (int) $raw;
    if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
        return (int) $default;
    }
    return $value;
}

// Upload limit in bytes for an upload_mb_* key (1 to 50 MB)
function kbfUploadLimitBytes($key, $defaultMb) {
    return kbfSettingInt($key, $defaultMb, 1, 50) * 1024 * 1024;
}

// Is this a valid "Website address": https, a host name (optional port),
// no path, no query, no fragment, no user info and no trailing slash
function kbfIsValidSiteBaseUrl($value) {
    return is_string($value)
        && strlen($value) <= 255
        && preg_match('#^https://[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*(?::\d{1,5})?$#', $value) === 1;
}

// The website address from Site settings (site_base_url), checked again on
// every read. Every redirect and email link is built from this, never from
// the request's Host header.
function kbfSiteBaseUrl() {
    $raw = kbfSetting('site_base_url', null);
    $url = trim((string) ($raw ?? ''));
    if (kbfIsValidSiteBaseUrl($url)) {
        return $url;
    }
    if (function_exists('kbfLogOnce')) {
        kbfLogOnce($raw === null
            ? 'Setting site_base_url is missing; links use https://kbf-uk.org. Set the Website address in Site settings.'
            : 'Setting site_base_url is not a valid https address; links use https://kbf-uk.org. Fix the Website address in Site settings.');
    }
    return 'https://kbf-uk.org';
}

// The request's copy of the Settings table ($reset = true empties it)
function kbfSettingsCache($reset = false) {
    static $cache = null;
    if ($reset) {
        $cache = null;
        return [];
    }
    if ($cache === null) {
        $cache = [];
        global $pdo;
        if ($pdo instanceof PDO) {
            try {
                foreach ($pdo->query("SELECT SettingKey, SettingValue FROM Settings")->fetchAll() as $row) {
                    $cache[(string) $row['SettingKey']] = $row['SettingValue'];
                }
            } catch (PDOException $e) {
                // Every setting falls back to its built-in default
                if (function_exists('kbfLogOnce')) {
                    kbfLogOnce('The Settings table could not be read (' . $e->getMessage() . '); every setting uses its built-in default.');
                } else {
                    error_log('kbfSetting: could not load Settings: ' . $e->getMessage());
                }
            }
        }
    }
    return $cache;
}

function kbfSettingsForget() {
    kbfSettingsCache(true);
}

/**
 * Build the round -> outcome -> {points, reason} map used when awarding points.
 *
 * Shape:
 *   $map['Final']['Winner'] = ['points' => 100, 'reason' => 'Winner'];
 *   $map['Final']['Loser']  = ['points' => 75,  'reason' => 'Runner-up'];
 *   ... and so on for each RoundType / Outcome row in RoundPoints.
 *
 * The result is cached in a static for the lifetime of the request, so repeated
 * calls within a single submission don't re-query.
 *
 * If the RoundPoints query throws or returns no rows, this falls back to the
 * historical hardcoded values (Final 100/75, SemiFinal 50/50, QuarterFinal
 * 30/30) so scoring can never be silently zeroed out by a missing table. A
 * warning is logged via error_log when the fallback is used.
 *
 * @param PDO $pdo
 * @return array
 */
function getRoundPointsMap($pdo) {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Historical values — the behaviour-preserving fallback (Final 100/75,
    // SemiFinal 50/50, QuarterFinal 30/30), from the shared placing table
    $fallback = [];
    foreach (KBF_PLACING_ROWS as $placing => $targets) {
        foreach ($targets as $t) {
            $fallback[$t[0]][$t[1]] = ['points' => KBF_PLACING_DEFAULTS[$placing], 'reason' => $t[2]];
        }
    }

    try {
        $stmt = $pdo->query("SELECT RoundType, Outcome, Points, Reason FROM RoundPoints");
        $rows = $stmt->fetchAll();

        if (!$rows) {
            error_log('getRoundPointsMap: RoundPoints returned no rows — using hardcoded fallback values');
            $cached = $fallback;
            return $cached;
        }

        $map = [];
        foreach ($rows as $row) {
            $map[$row['RoundType']][$row['Outcome']] = [
                'points' => (int)$row['Points'],
                'reason' => (string)$row['Reason'],
            ];
        }

        $cached = $map;
        return $cached;
    } catch (PDOException $e) {
        error_log('getRoundPointsMap: query failed (' . $e->getMessage() . ') — using hardcoded fallback values');
        $cached = $fallback;
        return $cached;
    }
}

/**
 * Does a category with this CategoryId exist?
 *
 * Used for data-driven validation so new categories are accepted without code
 * changes. Returns false (rejecting the value) on query failure.
 *
 * @param PDO       $pdo
 * @param int|mixed $categoryId
 * @return bool
 */
function categoryExists($pdo, $categoryId) {
    try {
        // Active grades only: a retired grade can no longer be chosen
        // (falls back to any grade until database/grading-settings.sql has run)
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM Categories WHERE CategoryId = ? AND IsActive = 1 LIMIT 1");
            $stmt->execute([$categoryId]);
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT 1 FROM Categories WHERE CategoryId = ? LIMIT 1");
            $stmt->execute([$categoryId]);
        }
        return $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        error_log('categoryExists failed for id "' . $categoryId . '": ' . $e->getMessage());
        return false;
    }
}

/**
 * Does a category with this ShortName exist? (e.g. 'A', 'C+', 'D+')
 *
 * Used for data-driven validation of the public rankings category filter.
 * Returns false (rejecting the value) on query failure.
 *
 * @param PDO          $pdo
 * @param string|mixed $shortName
 * @return bool
 */
function categoryShortNameExists($pdo, $shortName) {
    try {
        // Active grades only (any grade until database/grading-settings.sql has run)
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM Categories WHERE ShortName = ? AND IsActive = 1 LIMIT 1");
            $stmt->execute([$shortName]);
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT 1 FROM Categories WHERE ShortName = ? LIMIT 1");
            $stmt->execute([$shortName]);
        }
        return $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        error_log('categoryShortNameExists failed for "' . $shortName . '": ' . $e->getMessage());
        return false;
    }
}

/**
 * Record the start of one scheduled job run in JobRuns.
 * Returns the run id, or 0 when it could not be written (never breaks the job).
 *
 * @param PDO    $pdo
 * @param string $jobName
 * @return int
 */
function kbfJobRunStart($pdo, $jobName) {
    try {
        $stmt = $pdo->prepare("INSERT INTO JobRuns (JobName, StartedDate, Status) VALUES (?, NOW(), 'ok')");
        $stmt->execute([substr((string) $jobName, 0, 60)]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('JobRuns start failed for ' . $jobName . ': ' . $e->getMessage());
        return 0;
    }
}

/**
 * Plain text with web addresses taken out, for news excerpts (lists, link
 * previews). Removes http(s):// and www. addresses and bare ones with a
 * path (kbf-uk.org/news), then tidies the spaces and empty brackets left
 * behind. The full post keeps its links. The same rule is in
 * js/news-helpers.js (KBFNews.excerpt).
 *
 * @param string $text plain text
 * @return string
 */
function kbfStripUrls($text) {
    $text = (string) $text;
    $text = preg_replace([
        '~\b(?:https?://|www\.)[^\s<>"\']+~iu',
        '~\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/[^\s<>"\']*~iu',
    ], ' ', $text) ?? $text;
    $text = preg_replace('/[(\[]\s*[)\]]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/**
 * Every run of a scheduled job checks in: one UPDATE of its Settings value
 * (job_checkin_broadcast_sender, job_checkin_ranking_calculator,
 * job_checkin_term_reset; created by database/email-verification.sql), no
 * new rows. The Overview's "Last ran" and overdue warnings use it. Before
 * that script has run the UPDATE changes nothing and the Overview uses
 * JobRuns as before. Never breaks the job.
 *
 * @param PDO    $pdo
 * @param string $jobName  e.g. 'broadcast-sender'
 */
function kbfJobCheckIn($pdo, $jobName) {
    try {
        $stmt = $pdo->prepare("UPDATE Settings SET SettingValue = DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') WHERE SettingKey = ?");
        $stmt->execute([kbfJobCheckInKey($jobName)]);
    } catch (PDOException $e) {
        error_log('Job check-in failed for ' . $jobName . ': ' . $e->getMessage());
    }
}

// The Settings key of a job's check-in time
function kbfJobCheckInKey($jobName) {
    return 'job_checkin_' . str_replace('-', '_', (string) $jobName);
}

/**
 * Record how a job run ended: 'ok' or 'error' and a one-line summary.
 *
 * @param PDO    $pdo
 * @param int    $runId    from kbfJobRunStart (0 = nothing to record)
 * @param string $status   'ok' or 'error'
 * @param string $summary  e.g. "Sent 18 emails, 4 waiting"
 */
function kbfJobRunFinish($pdo, $runId, $status, $summary) {
    if (!$runId) {
        return;
    }
    try {
        $stmt = $pdo->prepare("UPDATE JobRuns SET FinishedDate = NOW(), Status = ?, Summary = ? WHERE Id = ?");
        $stmt->execute([$status === 'error' ? 'error' : 'ok', substr((string) $summary, 0, 500), (int) $runId]);
    } catch (PDOException $e) {
        error_log('JobRuns finish failed for run ' . (int) $runId . ': ' . $e->getMessage());
    }
}

// ============================================
// KBF IDS (KBFUK001, KBFUK002, ...)
// ============================================

/**
 * The next KBF ID, e.g. "KBFUK123". Call inside the caller's transaction:
 * the highest number used so far is kept in Settings (last_kbf_id_number),
 * read with FOR UPDATE (so two sign-ups never get the same number) and saved
 * as the new number. When the row is missing it is set up once from the
 * highest KBF ID in Users and PlayerRegistrations. A number that is somehow
 * already taken is skipped.
 *
 * @param PDO $pdo
 * @return string
 */
function kbfNextKbfId($pdo) {
    // Every caller locks the two tables in this one order (Users, then
    // PlayerRegistrations), so a sign-up and an admin "add player" at the
    // same moment wait for each other instead of deadlocking
    $pdo->query("SELECT UserId FROM Users ORDER BY UserId DESC LIMIT 1 FOR UPDATE");
    $pdo->query("SELECT RegistrationId FROM PlayerRegistrations ORDER BY RegistrationId DESC LIMIT 1 FOR UPDATE");

    $stmt = $pdo->prepare("SELECT SettingValue FROM Settings WHERE SettingKey = 'last_kbf_id_number' FOR UPDATE");
    $stmt->execute();
    $stored = $stmt->fetchColumn();

    if ($stored === false || !preg_match('/^\d+$/', (string) $stored)) {
        // First use: the highest number in either table (one full read)
        $lastNumber = 0;
        foreach (['Users', 'PlayerRegistrations'] as $table) {
            $max = $pdo->query("
                SELECT MAX(CAST(SUBSTRING(PlayerId, " . (strlen(KBF_ID_PREFIX) + 1) . ") AS UNSIGNED)) FROM $table
                WHERE PlayerId LIKE '" . KBF_ID_PREFIX . "%'
            ")->fetchColumn();
            $lastNumber = max($lastNumber, (int) $max);
        }
    } else {
        $lastNumber = (int) $stored;
    }

    $taken = $pdo->prepare("
        SELECT (SELECT COUNT(*) FROM Users WHERE PlayerId = ?)
             + (SELECT COUNT(*) FROM PlayerRegistrations WHERE PlayerId = ?)
    ");
    for ($i = 0; $i < 1000; $i++) {
        $lastNumber++;
        $kbfId = KBF_ID_PREFIX . str_pad((string) $lastNumber, 3, '0', STR_PAD_LEFT);
        $taken->execute([$kbfId, $kbfId]);
        if ((int) $taken->fetchColumn() === 0) {
            break;
        }
    }

    $save = $pdo->prepare("
        INSERT INTO Settings (SettingKey, SettingValue) VALUES ('last_kbf_id_number', ?)
        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
    ");
    $save->execute([(string) $lastNumber]);
    return $kbfId;
}

// ============================================
// TERMS VERSION
// ============================================

/**
 * The current terms version (setting terms_version, e.g. "2026-11"), as
 * saved in TermsVersion when someone agrees to the terms (at most 20
 * characters). Used by registration and by staff adding a player.
 *
 * @return string
 */
function kbfTermsVersion() {
    $version = substr(trim((string) kbfSetting('terms_version', '2026-11')), 0, 20);
    return $version === '' ? '2026-11' : $version;
}

// ============================================
// REMOVED PLAYERS (People: removed at their request, Users.IsRemoved = 1)
// ============================================

// The four player slots of a Matches row as selected by the public match
// queries: removed flag => [first name, surname, KBF ID, user id, grade]
const KBF_MATCH_SLOTS = [
    'P1Removed' => ['P1FirstName', 'P1LastName', 'P1PlayerId', 'Player1Id', 'P1Category'],
    'Partner1Removed' => ['Partner1FirstName', 'Partner1LastName', 'Partner1PlayerId', 'Partner1Id', 'Partner1Category'],
    'P2Removed' => ['P2FirstName', 'P2LastName', 'P2PlayerId', 'Player2Id', 'P2Category'],
    'Partner2Removed' => ['Partner2FirstName', 'Partner2LastName', 'Partner2PlayerId', 'Partner2Id', 'Partner2Category'],
];

/**
 * Show removed players in match rows as "Former member" with nothing that
 * links to a profile (no user id, KBF ID or grade). The *Removed flag
 * columns are dropped from every row.
 *
 * @param array $rows      match rows with P1Removed, Partner1Removed, P2Removed, Partner2Removed
 * @param bool  $keepIds   true for staff pages that edit results (user ids kept)
 * @return array
 */
function kbfMaskRemovedInMatches(array $rows, $keepIds = false) {
    foreach ($rows as &$row) {
        foreach (KBF_MATCH_SLOTS as $flag => $keys) {
            if (!empty($row[$flag])) {
                $row[$keys[0]] = 'Former';
                $row[$keys[1]] = 'member';
                if (array_key_exists($keys[2], $row)) {
                    $row[$keys[2]] = null;
                }
                if (!$keepIds && array_key_exists($keys[3], $row)) {
                    $row[$keys[3]] = null;
                }
                if (array_key_exists($keys[4], $row)) {
                    $row[$keys[4]] = null;
                }
            }
            unset($row[$flag]);

            // Deactivated players (SEC-18): for visitors who are not staff
            // the name stays (results stay readable) but nothing links to a
            // profile: no user id, KBF ID or grade
            $inactiveFlag = str_replace('Removed', 'Inactive', $flag);
            if (!empty($row[$inactiveFlag]) && !$keepIds) {
                foreach ([$keys[2], $keys[3], $keys[4]] as $key) {
                    if (array_key_exists($key, $row)) {
                        $row[$key] = null;
                    }
                }
            }
            unset($row[$inactiveFlag]);
        }
    }
    unset($row);
    return $rows;
}

// ============================================
// PLAYER PRIVACY ON THE PUBLIC SITE
//   * Year of birth and age are sent only to signed-in staff.
//   * Under 18 = YearOfBirth set and (current year - YearOfBirth) <= 18.
//     Their photo is never sent to anyone else (no path at all); pages show
//     initials instead. No YearOfBirth: not affected.
//   * A news image that is an under-18 player's photo is left out.
// ============================================

function kbfIsUnder18($yearOfBirth) {
    $y = (int) $yearOfBirth;
    return $y > 0 && ((int) date('Y') - $y) <= 18;
}

// Signed-in staff (a Super Admin or anyone holding a permission)
function kbfViewerIsStaff() {
    static $staff = null;
    if ($staff === null) {
        $staff = function_exists('isLoggedIn') && function_exists('isStaff') && isLoggedIn() && isStaff();
    }
    return $staff;
}

// Lowest year of birth that still counts as under 18 this year
function kbfUnder18FromYear() {
    return (int) date('Y') - 18;
}

// For visitors who are not staff: drop under-18 photos and every year of
// birth from player rows. $photoKeys: photo fields; $yobKey: year-of-birth
// field (read, then removed). Staff get the rows unchanged.
function kbfPublicPlayerRows(array $rows, array $photoKeys = ['ProfilePhoto'], $yobKey = 'YearOfBirth') {
    if (kbfViewerIsStaff()) {
        return $rows;
    }
    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }
        if (array_key_exists($yobKey, $row)) {
            if (kbfIsUnder18($row[$yobKey])) {
                foreach ($photoKeys as $k) {
                    if (array_key_exists($k, $row)) {
                        $row[$k] = null;
                    }
                }
            }
            unset($row[$yobKey]);
        }
    }
    unset($row);
    return $rows;
}

// Same for one row
function kbfPublicPlayerRow(array $row, array $photoKeys = ['ProfilePhoto'], $yobKey = 'YearOfBirth') {
    $rows = kbfPublicPlayerRows([$row], $photoKeys, $yobKey);
    return $rows[0];
}

// News posts for visitors who are not staff: an image that is an under-18
// player's profile photo (e.g. an older promotion post) is left out.
function kbfPublicPostImages($pdo, array $posts, $imageKey = 'FeaturedImage') {
    if (kbfViewerIsStaff()) {
        return $posts;
    }
    $paths = [];
    foreach ($posts as $p) {
        if (is_array($p) && !empty($p[$imageKey])) {
            $paths[(string) $p[$imageKey]] = true;
        }
    }
    if (!$paths) {
        return $posts;
    }
    $list = array_keys($paths);
    $hidden = [];
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT ProfilePhoto FROM Users
            WHERE ProfilePhoto IN (" . implode(',', array_fill(0, count($list), '?')) . ")
              AND YearOfBirth IS NOT NULL AND YearOfBirth > 0 AND YearOfBirth >= ?
        ");
        $stmt->execute(array_merge($list, [kbfUnder18FromYear()]));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $hidden[(string) $path] = true;
        }
    } catch (PDOException $e) {
        // Fail safe: leave out every image that is any player's photo
        error_log('kbfPublicPostImages: ' . $e->getMessage());
        foreach ($list as $path) {
            if (strpos($path, '/uploads/profiles/') === 0 || strpos($path, '/uploads/registrations/') === 0) {
                $hidden[$path] = true;
            }
        }
    }
    foreach ($posts as &$p) {
        if (is_array($p) && !empty($p[$imageKey]) && isset($hidden[(string) $p[$imageKey]])) {
            $p[$imageKey] = null;
        }
    }
    unset($p);
    return $posts;
}
?>
