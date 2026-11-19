<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/config-helpers.php';

// ============================================
// ADMIN MENU COUNTS
//
//   GET   the small counts shown in the staff header and menu on phones
//
// Returns only the counts the signed-in person may see:
//   approvals     registrations, tournaments and results waiting for a
//                 decision (players.approve_registrations and/or
//                 tournaments.approve); left out otherwise
//   resultsDue    approved tournaments that have finished and have no
//                 results yet (all tournaments for tournaments.approve or
//                 tournaments.manage_all; their own for organise_own);
//                 left out otherwise
// Worked out at most once a minute per person and set of permissions (a
// small file cache), and the browser may keep the answer for 60 seconds.
// ============================================

if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}
if (!isStaff()) {
    sendJSON(['success' => false, 'message' => 'Insufficient permissions'], 403);
}

const KBF_ADMIN_COUNTS_SECONDS = 60;

$userId = (int) $_SESSION['user_id'];
$can = [
    'registrations' => hasPermission('players.approve_registrations'),
    'approve' => hasPermission('tournaments.approve'),
    'allTournaments' => hasAnyPermission(['tournaments.approve', 'tournaments.manage_all']),
    'ownTournaments' => hasPermission('tournaments.organise_own'),
];

// The cache key covers who is asking and what they may see, so a change of
// access gives fresh counts at once
$cacheFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'kbf-admin-counts-'
    . hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|' . $userId . '|' . json_encode($can)) . '.json';

$counts = null;
$age = @filemtime($cacheFile);
if ($age !== false && time() - $age < KBF_ADMIN_COUNTS_SECONDS) {
    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        $counts = $cached;
    }
}

if ($counts === null) {
    $counts = [];
    try {
        if ($can['registrations'] || $can['approve']) {
            $waiting = 0;
            if ($can['registrations']) {
                $waiting += (int) $pdo->query("SELECT COUNT(*) FROM PlayerRegistrations WHERE Status = 'Pending'")->fetchColumn();
            }
            if ($can['approve']) {
                $waiting += (int) $pdo->query("SELECT COUNT(*) FROM Tournaments WHERE Status = 'Pending'")->fetchColumn();
                $waiting += (int) $pdo->query("SELECT COUNT(*) FROM ResultsApprovalQueue WHERE Status = 'Pending'")->fetchColumn();
            }
            $counts['approvals'] = $waiting;
        }
        if ($can['allTournaments'] || $can['ownTournaments']) {
            require_once __DIR__ . '/tournament-helpers.php';
            $sql = "SELECT COUNT(*) FROM Tournaments t WHERE " . kbfTournamentStatusSql() . " = 'due'";
            $params = [];
            if (!$can['allTournaments']) {
                $sql .= " AND t.CreatedByAdminId = ?";
                $params[] = $userId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $counts['resultsDue'] = (int) $stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('admin-counts.php: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'The counts could not be loaded.'], 500);
    }
    @file_put_contents($cacheFile, json_encode($counts), LOCK_EX);
}

// Private to this person; the browser may reuse it for a minute
header('Cache-Control: private, max-age=' . KBF_ADMIN_COUNTS_SECONDS);
header_remove('Pragma');
sendJSON(['success' => true, 'counts' => $counts]);
