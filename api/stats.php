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
$cacheFile = sys_get_temp_dir() . '/kbf_stats_cache.json';
$cacheTTL  = 60;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached !== null) {
        sendJSON($cached);
    }
}

try {
    // Single round-trip: all four counts in one query instead of four sequential queries.
    // Using correlated subqueries keeps the logic readable and avoids cross-joins.
    $stmt = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM Users       WHERE IsActive = 1 AND IsRemoved = 0) AS totalPlayers,
            (SELECT COUNT(*) FROM Tournaments WHERE Status = 'Completed') AS totalTournaments,
            -- Organisers: distinct organiser names on Approved and Completed
            -- tournaments, compared ignoring case and surrounding spaces (same response field)
            (SELECT COUNT(DISTINCT LOWER(TRIM(OrganiserName))) FROM Tournaments
              WHERE Status IN ('Approved', 'Completed') AND OrganiserName IS NOT NULL AND TRIM(OrganiserName) <> '') AS totalClubs,
            (SELECT COUNT(*) FROM Matches     WHERE IsApproved = 1)       AS totalMatches
    ");
    $row = $stmt->fetch();

    $payload = [
        'success' => true,
        'stats'   => [
            'totalPlayers'     => (int)$row['totalPlayers'],
            'totalTournaments' => (int)$row['totalTournaments'],
            'totalClubs'       => (int)$row['totalClubs'],
            'totalMatches'     => (int)$row['totalMatches'],
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
