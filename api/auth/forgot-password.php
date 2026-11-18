<?php
require_once __DIR__ . '/../security-init.php';
require_once '../config.php';
require_once 'email-config.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Blocked while a Super Admin is viewing as someone else
requireNotImpersonating();

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['email']) || empty(trim($data['email']))) {
    sendJSON(['success' => false, 'message' => 'Email address is required'], 400);
}

$email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);

if (!$email) {
    sendJSON(['success' => false, 'message' => 'Invalid email format'], 400);
}

try {
    // Rate limiting: Check for recent reset requests from this IP
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as recent_requests 
        FROM PasswordResets 
        WHERE IpAddress = ? AND CreatedAt > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$ipAddress]);
    $result = $stmt->fetch();
    
    if ($result['recent_requests'] >= 3) {
        sendJSON([
            'success' => false,
            'message' => 'Too many reset requests. Please try again in 15 minutes.'
        ], 429);
    }
    
    // Only people who can sign in (active, sign-in enabled, a password set,
    // and staff) can reset a password
    $stmt = $pdo->prepare("
        SELECT u.UserId, u.Email, u.FirstName, u.LastName, u.PasswordHash
        FROM Users u
        WHERE u.Email = ? AND " . KBF_CAN_SIGN_IN_SQL . "
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // SECURITY: Always show same message whether user exists or not (prevent email enumeration).
    // Only proceed with token generation if the user can sign in.
    if ($user && !empty($user['PasswordHash'])) {
        // Delete any existing unused tokens for this user
        $stmt = $pdo->prepare("
            DELETE FROM PasswordResets 
            WHERE UserId = ? AND Used = 0
        ");
        $stmt->execute([$user['UserId']]);
        
        // Generate secure random token
        $token = bin2hex(random_bytes(32)); // 64 character hex string
        
        // Set expiration time (1 hour from now)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store only a SHA-256 hash of the token; the plain token is emailed
        $tokenHash = hash('sha256', $token);

        // Insert reset token into database
        $stmt = $pdo->prepare("
            INSERT INTO PasswordResets (UserId, Email, Token, ExpiresAt, IpAddress, CreatedAt)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user['UserId'],
            $user['Email'],
            $tokenHash,
            $expiresAt,
            $ipAddress
        ]);
        
        // Send password reset email
        // Locked event: always on. The link is never stored (secrets).
        $results = notify('password_reset_requested', [
            'person' => (int) $user['UserId'],
            'values' => [
                'reset_link' => kbfSiteLink($pdo) . '/reset-password.html?token=' . urlencode($token),
            ],
            'secrets' => ['reset_link'],
            'relatedType' => 'user',
            'relatedId' => (int) $user['UserId'],
        ]);

        if (($results['person'] ?? '') !== 'sent') {
            error_log('Failed to send password reset email for user ' . (int) $user['UserId']);
            // Don't reveal email sending failure to user for security
        }
    }
    
    // Always return success message to prevent email enumeration
    sendJSON([
        'success' => true,
        'message' => 'If an account exists with this email, you will receive a password reset link shortly.'
    ]);
    
} catch(PDOException $e) {
    error_log('Password reset error: ' . $e->getMessage());
    sendJSON([
        'success' => false,
        'message' => 'An error occurred. Please try again later.'
    ], 500);
}
?>