<?php
/**
 * Public one-click unsubscribe page (no login).
 * Reached from broadcast email links as /unsubscribe.php?token=xxx
 *
 * The token IS the authorisation, so there is no CSRF check (standard pattern
 * for email unsubscribe). It is a GET that performs a state change, which is
 * acceptable for one-click unsubscribe. We never reveal whether a given token
 * exists — invalid/missing/unknown tokens all get the same neutral page.
 */

// ---- Force HTTPS (before any output / DB work). Proxy-aware: Hostinger sits
// behind a reverse proxy, so check the forwarded-proto header too. (This is
// more robust than security-init.php, which only checks $_SERVER['HTTPS'].) ----
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (!$isHttps) {
    $host = $_SERVER['HTTP_HOST'] ?? 'kbf-uk.org';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/unsubscribe.php';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

require_once __DIR__ . '/api/config.php'; // provides $pdo

/**
 * Render a self-contained branded page and stop. Always HTTP 200 (we don't
 * leak token validity via status codes).
 */
function renderPage($heading, $message, $ok) {
    $accent = $ok ? '#10b981' : '#f59e0b';
    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <meta name='robots' content='noindex,nofollow'>
    <title>KBF-UK — Unsubscribe</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background-color: #0f172a; color: #f1f5f9; margin: 0; padding: 0;
            display: flex; min-height: 100vh; align-items: center; justify-content: center;
        }
        .card {
            max-width: 520px; width: 100%; margin: 20px;
            background-color: #1e293b; border: 4px solid #374151;
        }
        .header {
            background-color: {$accent}; padding: 28px; text-align: center;
            border-bottom: 4px solid #374151;
        }
        .header h1 {
            margin: 0; color: #0f172a; font-size: 22px; font-weight: 900;
            text-transform: uppercase; letter-spacing: 2px;
        }
        .content { padding: 36px 28px; text-align: center; }
        .content h2 {
            color: {$accent}; font-size: 18px; font-weight: 900;
            text-transform: uppercase; margin: 0 0 16px;
        }
        .content p { line-height: 1.6; color: #cbd5e1; font-weight: 600; margin: 12px 0; }
        .footer {
            background-color: #0f172a; padding: 18px; text-align: center;
            border-top: 4px solid #374151; font-size: 12px; color: #64748b;
        }
        .footer a { color: {$accent}; text-decoration: none; }
    </style>
</head>
<body>
    <div class='card'>
        <div class='header'><h1>🏸 KBF-UK</h1></div>
        <div class='content'>
            <h2>{$h}</h2>
            <p>{$m}</p>
        </div>
        <div class='footer'>
            <p><a href='https://kbf-uk.org'>kbf-uk.org</a></p>
        </div>
    </div>
</body>
</html>";
    exit;
}

// ---- Validate the token format (hex, 1-64 chars). Tokens are bin2hex(random_bytes(32)) = 64 hex chars. ----
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if ($token === '' || strlen($token) > 64 || !ctype_xdigit($token)) {
    // Missing or malformed — neutral message, no detail leakage.
    renderPage(
        'Invalid or Expired Link',
        "This unsubscribe link is invalid or has expired. If you keep receiving emails you didn't expect, please contact us.",
        false
    );
}

try {
    // ---- Look up the recipient by exact token match. ----
    $stmt = $pdo->prepare("SELECT UserId, EmailOptOut FROM Users WHERE UnsubscribeToken = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        // Unknown token — same neutral page as malformed (don't reveal existence).
        renderPage(
            'Invalid or Expired Link',
            "This unsubscribe link is invalid or has expired. If you keep receiving emails you didn't expect, please contact us.",
            false
        );
    }

    // ---- Opt out (idempotent: already-opted-out still shows success). ----
    if ((int) $user['EmailOptOut'] !== 1) {
        $upd = $pdo->prepare("UPDATE Users SET EmailOptOut = 1 WHERE UserId = ?");
        $upd->execute([$user['UserId']]);
    }

    renderPage(
        "You've Been Unsubscribed",
        "You will no longer receive broadcast emails from KBF-UK. You may still receive essential account-related messages (e.g. password resets).",
        true
    );

} catch (PDOException $e) {
    error_log('Unsubscribe error: ' . $e->getMessage());
    // Generic message — don't leak whether the token exists or why it failed.
    renderPage(
        'Something Went Wrong',
        "We couldn't process your request right now. Please try again later, or contact us if the problem persists.",
        false
    );
}
?>
