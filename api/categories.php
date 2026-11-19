<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/grading.php';

// GET - Fetch the active grades in rank order (best first).
// Retired grades (Categories.IsActive = 0) are left out of every list:
// public tabs, registration, grade dropdowns and broadcast targets.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $categories = kbfActiveGrades($pdo);

        kbfPublicCache(60);
        sendJSON([
            'success' => true,
            'categories' => $categories
        ]);

    } catch(PDOException $e) {
        error_log('Database error in categories.php: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}
?>
