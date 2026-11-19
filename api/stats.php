<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Stats are public, non-sensitive data — override the blanket no-cache header
// set by security-init.php so browsers and proxies can cache for 60 seconds
// (visitors who are not signed in; see kbfPublicCache in security-init.php).
kbfPublicCache(60);

// ============================================
// FILE-BASED CACHE (60-second TTL)
// Avoids hitting the database on every page load.
// ============================================
// v2: totalMatches was replaced by totalPromoted, so an old cache file is ignored
$cacheFile = sys_get_temp_dir() . '/kbf_stats_cache_v2.json';
$cacheTTL  = 60;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached !== null) {
        sendJSON($cached);
    }
}

try {
    // Single round-trip: all four figures in one query instead of four sequential queries.
    // Using correlated subqueries keeps the logic readable and avoids cross-joins.
    $stmt = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM Users       WHERE IsActive = 1 AND IsRemoved = 0) AS totalPlayers,
            (SELECT COUNT(*) FROM Tournaments WHERE Status = 'Completed') AS totalTournaments,
            -- Organisers: distinct organiser names on Approved and Completed
            -- tournaments, compared ignoring case and surrounding spaces (same response field)
            (SELECT COUNT(DISTINCT LOWER(TRIM(OrganiserName))) FROM Tournaments
              WHERE Status IN ('Approved', 'Completed') AND OrganiserName IS NOT NULL AND TRIM(OrganiserName) <> '') AS totalClubs,
            -- Players promoted: distinct players with any grade change to a higher
            -- grade (lower Categories.DisplayOrder). Same rule as kbfLastGradeChanges
            -- in points-rules.php: both grades known, and not an admin move.
            (SELECT COUNT(DISTINCT rh.PlayerId)
               FROM PlayerRankingHistory rh
               JOIN Categories oc ON oc.CategoryId = rh.OldCategoryId
               JOIN Categories nc ON nc.CategoryId = rh.NewCategoryId
              WHERE nc.DisplayOrder < oc.DisplayOrder
                AND (rh.Reason IS NULL OR rh.Reason NOT LIKE 'Moved by an admin%')) AS totalPromoted
    ");
    $row = $stmt->fetch();

    $payload = [
        'success' => true,
        'stats'   => [
            'totalPlayers'     => (int)$row['totalPlayers'],
            'totalTournaments' => (int)$row['totalTournaments'],
            'totalClubs'       => (int)$row['totalClubs'],
            'totalPromoted'    => (int)$row['totalPromoted'],
        ],
    ];

    // Write to cache — best-effort, suppress errors if /tmp is unwritable
    @file_put_contents($cacheFile, json_encode($payload), LOCK_EX);

    sendJSON($payload);

} catch (PDOException $e) {
    error_log('Database error in stats.php: ' . $e->getMessage());
    sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
}
?>
