<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isset($_GET['token']) || empty(trim($_GET['token']))) {
    sendJSON(['success' => false, 'message' => 'Token is required'], 400);
}

$token = sanitizeInput($_GET['token']);

try {
    // Validate token format (should be 64 character hex string)
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        sendJSON([
            'success' => false,
            'message' => 'Invalid token format'
        ], 400);
    }
    
    // Check if token exists and is valid
    $stmt = $pdo->prepare("
        SELECT pr.ResetId, pr.UserId, pr.Email, pr.ExpiresAt, pr.Used,
               u.FirstName, u.LastName
        FROM PasswordResets pr
        JOIN Users u ON pr.UserId = u.UserId
        WHERE pr.Token = ? AND " . KBF_CAN_SIGN_IN_SQL . "
    ");
    // Tokens are stored as SHA-256 hashes; look up by hash
    $stmt->execute([hash('sha256', strtolower($token))]);
    $reset = $stmt->fetch();
    
    // Check if token exists
    if (!$reset) {
        sendJSON([
            'success' => false,
            'message' => 'Invalid or expired reset link'
        ], 404);
    }
    
    // Check if token has been used
    if ($reset['Used'] == 1) {
        sendJSON([
            'success' => false,
            'message' => 'This reset link has already been used'
        ], 400);
    }
    
    // Check if token has expired
    $now = new DateTime();
    $expiresAt = new DateTime($reset['ExpiresAt']);
    
    if ($now > $expiresAt) {
        sendJSON([
            'success' => false,
            'message' => 'This reset link has expired. Please request a new one.'
        ], 400);
    }
    
    // Token is valid
    sendJSON([
        'success' => true,
        'message' => 'Token is valid',
        'email' => $reset['Email'],
        'name' => $reset['FirstName'] . ' ' . $reset['LastName']
    ]);
    
} catch(PDOException $e) {
    error_log('Token verification error: ' . $e->getMessage());
    sendJSON([
        'success' => false,
        'message' => 'An error occurred. Please try again later.'
    ], 500);
}
?>