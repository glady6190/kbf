<?php
/**
 * Public unsubscribe page (no login).
 * Reached from broadcast email links as /unsubscribe.php?token=xxx
 *
 * Opening the link never changes anything: mail systems and link scanners
 * (Outlook Safe Links, company scanners, anti-virus tools) open every link
 * in an email by themselves. The page shows which address will be
 * unsubscribed and an "Unsubscribe" button; only pressing it (a POST that
 * carries the same token, checked again) unsubscribes. Afterwards the page
 * offers "Subscribe again" (also a POST) and the contact email for help.
 *
 * The token IS the authorisation: it is long and random and only ever sent
 * to that address. Invalid, missing and unknown tokens all get the same
 * neutral page, so nobody can learn whether a token exists. A standard
 * one-click POST (List-Unsubscribe=One-Click) is also accepted.
 */

// ---- Force HTTPS: done by api/config.php before the session starts
// (proxy-aware). It redirects to the Website address from Site settings,
// never to the request's Host header. ----

require_once __DIR__ . '/api/config.php'; // provides $pdo
require_once __DIR__ . '/api/config-helpers.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
// The page uses only its own inline styles and posts only to itself (SEC-16)
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'; object-src 'none'");

function unsubContactEmail() {
    $email = trim((string) kbfSetting('contact_email', 'admin@kbf-uk.org'));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'admin@kbf-uk.org';
}

/**
 * Render a self-contained page and stop. Always HTTP 200 (token validity is
 * never shown through status codes).
 * $paragraphs: plain text lines (escaped here)
 * $form: null, or ['action' => 'unsubscribe'|'resubscribe', 'token' => ..., 'label' => ...]
 * $secondary: null, or the same shape, shown as a smaller second button
 */
function renderPage($heading, array $paragraphs, $ok, $form = null, $secondary = null) {
    $accent = $ok ? '#10b981' : '#fbbf24';
    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $contact = unsubContactEmail();
    $contactHtml = htmlspecialchars($contact, ENT_QUOTES, 'UTF-8');
    $site = htmlspecialchars(kbfSiteBaseUrl(), ENT_QUOTES, 'UTF-8');
    $siteName = htmlspecialchars(preg_replace('#^https://#', '', kbfSiteBaseUrl()), ENT_QUOTES, 'UTF-8');

    $body = '';
    foreach ($paragraphs as $p) {
        $body .= '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $formHtml = function ($f, $primary) {
        if ($f === null) {
            return '';
        }
        return "<form method='post' action='/unsubscribe.php'>"
            . "<input type='hidden' name='token' value='" . htmlspecialchars($f['token'], ENT_QUOTES, 'UTF-8') . "'>"
            . "<input type='hidden' name='action' value='" . htmlspecialchars($f['action'], ENT_QUOTES, 'UTF-8') . "'>"
            . "<button type='submit' class='" . ($primary ? 'btn' : 'btn btn-ghost') . "'>" . htmlspecialchars($f['label'], ENT_QUOTES, 'UTF-8') . "</button>"
            . "</form>";
    };

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <meta name='robots' content='noindex,nofollow'>
    <title>Unsubscribe - KBF-UK</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background-color: #0f172a; color: #f1f5f9; margin: 0; padding: 0;
            display: flex; min-height: 100vh; align-items: center; justify-content: center;
        }
        .card { max-width: 520px; width: 100%; margin: 16px; background-color: #1e293b; border: 4px solid #374151; }
        .header { background-color: {$accent}; padding: 24px; text-align: center; border-bottom: 4px solid #374151; }
        .header h1 { margin: 0; color: #0f172a; font-size: 22px; font-weight: 900; text-transform: uppercase; letter-spacing: 2px; }
        .content { padding: 32px 24px; text-align: center; }
        .content h2 { color: {$accent}; font-size: 18px; font-weight: 900; text-transform: uppercase; margin: 0 0 16px; }
        .content p { line-height: 1.6; color: #cbd5e1; font-weight: 600; margin: 12px 0; overflow-wrap: anywhere; }
        .content strong { color: #f1f5f9; }
        form { margin: 20px 0 0; }
        .btn {
            min-height: 44px; min-width: 44px; padding: 12px 24px; border: 4px solid #0f172a;
            background-color: {$accent}; color: #0f172a; font-weight: 900; font-size: 16px;
            text-transform: uppercase; cursor: pointer;
        }
        .btn-ghost { background-color: transparent; color: #f1f5f9; border-color: #64748b; font-size: 14px; }
        .btn:focus-visible { outline: 3px solid #f1f5f9; outline-offset: 3px; }
        .help { margin-top: 24px; font-size: 14px; }
        .help a, .footer a { color: {$accent}; }
        .footer { background-color: #0f172a; padding: 16px; text-align: center; border-top: 4px solid #374151; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <main class='card'>
        <div class='header'><h1>KBF-UK</h1></div>
        <div class='content'>
            <h2>{$h}</h2>
            {$body}
            " . $formHtml($form, true) . "
            " . $formHtml($secondary, false) . "
            <p class='help'>Need help? Email us at <a href='mailto:{$contactHtml}'>{$contactHtml}</a>.</p>
        </div>
        <div class='footer'><p><a href='{$site}'>{$siteName}</a></p></div>
    </main>
</body>
</html>";
    exit;
}

function renderInvalid() {
    renderPage(
        'Invalid or expired link',
        ['This unsubscribe link is invalid or has expired. If you keep receiving emails you did not expect, please contact us at the address below and we will stop them.'],
        false
    );
}

// The token: hex, at most 64 characters (tokens are bin2hex(random_bytes(32)))
function unsubToken($raw) {
    $token = is_string($raw) ? trim($raw) : '';
    return ($token !== '' && strlen($token) <= 64 && ctype_xdigit($token)) ? $token : null;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
// Standard one-click unsubscribe: POST to the link with this body
$oneClick = $isPost && (($_POST['List-Unsubscribe'] ?? '') === 'One-Click');

$token = $isPost && !$oneClick ? unsubToken($_POST['token'] ?? null) : unsubToken($_GET['token'] ?? null);
if ($token === null) {
    renderInvalid();
}

try {
    // ---- Look up the recipient by exact token match ----
    $stmt = $pdo->prepare("SELECT UserId, Email, EmailOptOut FROM Users WHERE UnsubscribeToken = ? AND COALESCE(IsRemoved, 0) = 0");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) {
        renderInvalid();
    }
    $email = (string) $user['Email'];
    $optedOut = (int) $user['EmailOptOut'] === 1;

    // ---- GET: show the address and the button; nothing changes ----
    if (!$isPost) {
        if ($optedOut) {
            renderPage(
                'Already unsubscribed',
                ['The address ' . $email . ' does not receive broadcast emails from KBF-UK.',
                 'Changed your mind? You can subscribe again.'],
                true,
                ['action' => 'resubscribe', 'token' => $token, 'label' => 'Subscribe again']
            );
        }
        renderPage(
            'Unsubscribe from KBF-UK emails?',
            ['This will stop broadcast emails (news and announcements) to:', $email,
             'You will still receive essential account emails, such as password resets.'],
            true,
            ['action' => 'unsubscribe', 'token' => $token, 'label' => 'Unsubscribe']
        );
    }

    // ---- POST: unsubscribe (or one-click), or subscribe again ----
    $action = $oneClick ? 'unsubscribe' : (string) ($_POST['action'] ?? '');
    if ($action === 'resubscribe') {
        if ($optedOut) {
            $pdo->prepare("UPDATE Users SET EmailOptOut = 0 WHERE UserId = ?")->execute([(int) $user['UserId']]);
        }
        renderPage(
            'Subscribed again',
            ['The address ' . $email . ' will receive broadcast emails from KBF-UK again.'],
            true,
            null,
            ['action' => 'unsubscribe', 'token' => $token, 'label' => 'Unsubscribe after all']
        );
    }
    if ($action !== 'unsubscribe') {
        renderInvalid();
    }

    // Idempotent: already unsubscribed still shows the confirmation
    if (!$optedOut) {
        $pdo->prepare("UPDATE Users SET EmailOptOut = 1 WHERE UserId = ?")->execute([(int) $user['UserId']]);
    }
    if ($oneClick) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unsubscribed';
        exit;
    }
    renderPage(
        'You have been unsubscribed',
        ['The address ' . $email . ' will no longer receive broadcast emails from KBF-UK.',
         'You may still receive essential account emails, such as password resets.',
         'Changed your mind? Subscribe again.'],
        true,
        ['action' => 'resubscribe', 'token' => $token, 'label' => 'Subscribe again']
    );

} catch (PDOException $e) {
    error_log('Unsubscribe error: ' . $e->getMessage());
    // Generic message: never shows whether the token exists or why it failed
    renderPage(
        'Something went wrong',
        ['We could not process your request right now. Please try again later, or contact us at the address below if the problem continues.'],
        false
    );
}
?>
