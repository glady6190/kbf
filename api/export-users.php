<?php
require_once __DIR__ . '/security-init.php';
require_once 'config.php';
require_once __DIR__ . '/report-helpers.php';

// Login (401), then CSRF on writes, then players.view_export (403).
// The export never includes emails or phone numbers; if they are ever added,
// include them only with players.contact_details.
if (!isLoggedIn()) {
    sendJSON(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        sendJSON(['success' => false, 'message' => 'Invalid security token'], 403);
    }
}
requirePermission('players.view_export');

// GET - Fetch distinct locations
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_locations') {
    try {
        $query = "SELECT DISTINCT Location FROM Users WHERE IsActive = 1 AND IsRemoved = 0 AND Location IS NOT NULL AND Location != ''";
        $query .= " ORDER BY Location ASC";
        
        $stmt = $pdo->query($query);
        $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        sendJSON([
            'success' => true,
            'locations' => $locations
        ]);
    } catch(PDOException $e) {
        error_log('Database error in export-users.php GET: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// POST - Export users
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!is_array($input) || !isset($input['locations']) || !is_array($input['locations']) || empty($input['locations'])) {
        sendJSON(['success' => false, 'message' => 'Please select at least one location'], 400);
    }
    
    if (!isset($input['format']) || !in_array($input['format'], ['csv', 'pdf'])) {
        sendJSON(['success' => false, 'message' => 'Invalid export format'], 400);
    }
    
    $locations = array_map('sanitizeInput', $input['locations']);
    $format = $input['format'];
    
    try {
        // Build query with location filter
        $placeholders = str_repeat('?,', count($locations) - 1) . '?';
        
        $query = "
            SELECT u.PlayerId, u.FirstName, u.LastName, u.Location, c.ShortName as Category
            FROM Users u
            LEFT JOIN Categories c ON u.CategoryId = c.CategoryId
            WHERE u.IsActive = 1 AND u.IsRemoved = 0 AND u.Location IN ($placeholders)
        ";
        
        $query .= " ORDER BY u.Location ASC, c.DisplayOrder ASC, u.TotalPoints DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($locations);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($users)) {
            sendJSON(['success' => false, 'message' => 'No users found for selected location(s)'], 404);
        }
        
        // Generate summary statistics
        $summary = generateSummary($users, $locations);
        
        // Export based on format
        if ($format === 'csv') {
            exportCSV($users, $summary, $locations);
        } elseif ($format === 'pdf') {
            exportPDF($users, $summary, $locations);
        }
        
    } catch(PDOException $e) {
        error_log('Database error in export-users.php POST: ' . $e->getMessage());
        sendJSON(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function generateSummary($users, $locations) {
    $summary = [
        'total' => count($users),
        'by_category' => [],
        'by_location' => []
    ];
    
    foreach ($users as $user) {
        // Count by category
        $category = $user['Category'] ?: 'Unranked';
        if (!isset($summary['by_category'][$category])) {
            $summary['by_category'][$category] = 0;
        }
        $summary['by_category'][$category]++;
        
        // Count by location (if multiple locations selected)
        if (count($locations) > 1) {
            $location = $user['Location'];
            if (!isset($summary['by_location'][$location])) {
                $summary['by_location'][$location] = 0;
            }
            $summary['by_location'][$location]++;
        }
    }
    
    // Sort categories in display order — driven by Categories.DisplayOrder so
    // new categories (C+, D+, future) sort correctly with no code change.
    // 'Unranked' always sorts last.
    global $pdo;
    $categoryOrder = [];
    try {
        $stmt = $pdo->query("SELECT ShortName, DisplayOrder FROM Categories ORDER BY DisplayOrder");
        foreach ($stmt->fetchAll() as $cat) {
            $categoryOrder[$cat['ShortName']] = (int)$cat['DisplayOrder'];
        }
    } catch (PDOException $e) {
        // No fixed grade list: grades then keep the order they were counted in
        error_log('export-users generateSummary: failed to load category order: ' . $e->getMessage());
        $categoryOrder = [];
    }
    $categoryOrder['Unranked'] = PHP_INT_MAX; // always sort last
    uksort($summary['by_category'], function($a, $b) use ($categoryOrder) {
        return ($categoryOrder[$a] ?? 99) <=> ($categoryOrder[$b] ?? 99);
    });
    
    ksort($summary['by_location']);
    
    return $summary;
}

function exportCSV($users, $summary, $locations) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Every cell, summary rows included, goes through the shared CSV cell
    // protection (kbfCsvCell: no cell can run as a formula)

    // Headers - Updated to 4 columns
    kbfCsvRow($output, ['Player ID', 'Name', 'Location', 'Grade']);

    // Data rows - Merged first and last name
    foreach ($users as $user) {
        kbfCsvRow($output, [
            $user['PlayerId'] ?: 'N/A',
            $user['FirstName'] . ' ' . $user['LastName'],  // Merged name
            $user['Location'],
            $user['Category'] ?: 'Unranked'
        ]);
    }

    // Add summary (rest stays the same)
    kbfCsvRow($output, []);
    kbfCsvRow($output, ['SUMMARY']);
    kbfCsvRow($output, ['Total Players', $summary['total']]);
    kbfCsvRow($output, []);

    kbfCsvRow($output, ['By Category']);
    foreach ($summary['by_category'] as $category => $count) {
        kbfCsvRow($output, ["Category $category", $count]);
    }

    if (count($locations) > 1 && !empty($summary['by_location'])) {
        kbfCsvRow($output, []);
        kbfCsvRow($output, ['By Location']);
        foreach ($summary['by_location'] as $location => $count) {
            kbfCsvRow($output, [$location, $count]);
        }
    }
    
    fclose($output);
    exit;
}

function exportPDF($users, $summary, $locations) {
    global $pdo;
    
    // Check if all locations are selected
    $allLocationsQuery = "SELECT COUNT(DISTINCT Location) as total FROM Users WHERE IsActive = 1 AND IsRemoved = 0 AND Location IS NOT NULL AND Location != ''";
    $stmt = $pdo->query($allLocationsQuery);
    $totalLocations = $stmt->fetch()['total'];
    
    $isAllLocations = (count($locations) == $totalLocations);
    
    // Generate PDF HTML content
    $html = generatePDFContent($users, $summary, $locations, $isAllLocations);
    
    // Force download directly to user (NO SERVER STORAGE)
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d_His') . '.html"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $html;
    exit;
}

function generatePDFContent($users, $summary, $locations, $isAllLocations = false) {
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Export Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: Arial, sans-serif; 
            padding: 30px;
            background: #0f172a;
            color: #f1f5f9;
            max-width: 1200px;
            margin: 0 auto;
        }
        .header { 
            background: #10b981; 
            color: #0f172a; 
            padding: 20px; 
            text-align: center;
            border: 4px solid #374151;
            margin-bottom: 30px;
        }
        .header h1 { 
            font-size: 28px; 
            font-weight: 900; 
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .header p { 
            margin-top: 10px;
            font-size: 13px;
            font-weight: bold;
        }
        table { 
            width: 100%; 
            border-collapse: collapse;
            background: #1e293b;
            border: 4px solid #374151;
            margin-bottom: 30px;
        }
        th { 
            background: #1e293b; 
            color: #10b981; 
            padding: 14px 10px; 
            text-align: center;
            font-size: 13px;
            font-weight: 900;
            text-transform: uppercase;
            border: 2px solid #374151;
            letter-spacing: 0.5px;
        }
        td { 
            padding: 12px 10px; 
            border: 2px solid #374151;
            font-size: 13px;
            text-align: center;
            line-height: 1.4;
            font-weight: 500;
        }
        tr:nth-child(even) { background: #0f172a; }
        tr:nth-child(odd) { background: #1e293b; }
        
        /* Make Player ID and Grade stand out */
        td:first-child { 
            font-weight: 700; 
            color: #94a3b8;
        }
        td:last-child { 
            font-weight: 700; 
            color: #10b981;
            font-size: 14px;
        }
        
        .summary { 
            background: #1e293b; 
            border: 4px solid #374151;
            padding: 20px;
        }
        .summary h2 { 
            background: #10b981; 
            color: #0f172a; 
            padding: 12px; 
            font-size: 18px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 15px;
        }
        .summary-item { 
            padding: 8px 0;
            border-bottom: 1px solid #374151;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
        }
        .summary-item:last-child { border-bottom: none; }
        .summary-label { 
            font-weight: bold;
            color: #94a3b8;
        }
        .summary-value { 
            color: #10b981;
            font-weight: 900;
        }
        .section-title {
            color: #10b981;
            font-weight: 900;
            font-size: 13px;
            margin-top: 15px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        
        /* Mobile optimizations */
        @media screen and (max-width: 600px) {
            body { padding: 10px; }
            .header { padding: 12px; border-width: 3px; }
            .header h1 { font-size: 18px; letter-spacing: 1px; }
            .header p { font-size: 9px; margin-top: 6px; }
            
            table { border-width: 3px; }
            
            th { 
                padding: 8px 4px; 
                font-size: 10px; 
                line-height: 1.2;
                letter-spacing: 0;
            }
            td { 
                padding: 7px 4px; 
                font-size: 11px;
                line-height: 1.4;
            }
            
            td:last-child { 
                font-size: 12px;
            }
            
            .summary { padding: 12px; border-width: 3px; }
            .summary h2 { font-size: 13px; padding: 8px; }
            .summary-item { font-size: 11px; padding: 7px 0; }
            .section-title { font-size: 11px; }
        }
        
        @media print {
            body { background: white; color: black; padding: 20px; }
            .header { background: #10b981; color: black; }
            th { background: #1e293b; color: #10b981; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏸 User Export Report</h1>
        <p>Generated: ' . date('d M Y H:i:s') . '</p>
        <p>Locations: ' . ($isAllLocations ? 'All Locations' : htmlspecialchars(implode(', ', $locations))) . '</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th style="width: 18%;">Player ID</th>
                <th style="width: 42%;">Name</th>
                <th style="width: 28%;">Location</th>
                <th style="width: 12%;">Grade</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($users as $user) {
        $fullName = htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']);
        $html .= '<tr>
            <td>' . htmlspecialchars($user['PlayerId'] ?: 'N/A') . '</td>
            <td>' . $fullName . '</td>
            <td>' . htmlspecialchars($user['Location']) . '</td>
            <td>' . htmlspecialchars($user['Category'] ?: '-') . '</td>
        </tr>';
    }
    
    $html .= '</tbody></table>
    
    <div class="summary">
        <h2>📊 Summary</h2>
        
        <div class="summary-item">
            <span class="summary-label">Total Players:</span>
            <span class="summary-value">' . $summary['total'] . '</span>
        </div>
        
        <div class="section-title">By Category</div>';
    
    foreach ($summary['by_category'] as $category => $count) {
        $html .= '<div class="summary-item">
            <span class="summary-label">Category ' . htmlspecialchars($category) . ':</span>
            <span class="summary-value">' . $count . ' players</span>
        </div>';
    }
    
    if (count($locations) > 1 && !empty($summary['by_location'])) {
        $html .= '<div class="section-title">By Location</div>';
        foreach ($summary['by_location'] as $location => $count) {
            $html .= '<div class="summary-item">
                <span class="summary-label">' . htmlspecialchars($location) . ':</span>
                <span class="summary-value">' . $count . ' players</span>
            </div>';
        }
    }
    
    $html .= '</div>
</body>
</html>';
    
    return $html;
}
?>