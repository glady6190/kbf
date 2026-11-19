<?php
// Include-only: refuse to run when requested directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/../security-init.php';
// KBF-UK emails.
// Event emails (approvals, password resets, promotions and the rest) are sent
// by notify() in api/notify.php. Their original wording is in
// api/email-templates.php and can be changed on the Notifications page.
// This file keeps the broadcast email, which wraps the admin's own message.
// Everything goes through the shared sender (api/mailer.php).

require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../email-templates.php';
require_once __DIR__ . '/../notify.php';

/**
 * Clean admin-authored broadcast HTML with the shared email allowlist
 * (p, br, b, strong, i, em, h2, h3, ul, ol, li and http, https or mailto links).
 */
function sanitizeBroadcastHtml($html) {
    return kbfCleanEmailHtml($html);
}

/**
 * Send a single broadcast email to one recipient.
 *
 * Wraps the admin's (cleaned) message in the KBF email layout and appends an
 * unsubscribe footer. Sent with priority bulk.
 *
 * @param string   $toEmail
 * @param string   $toName
 * @param string   $subject         broadcast subject line
 * @param string   $bodyHtml        admin-authored HTML (cleaned here before embedding)
 * @param string   $unsubscribeUrl  per-recipient unsubscribe link
 * @param int|null $broadcastId     EmailBroadcasts.BroadcastId (for the log)
 * @param int|null $queueId         EmailQueue.QueueId (for the log)
 * @param array    $attachments     [['file' => relative path, 'name' => ...]]
 * @param string   $priority        'bulk' (broadcasts) or 'normal' (a test to the admin)
 * @return string 'sent', 'waiting' or 'failed'
 */
function sendBroadcastEmail($toEmail, $toName, $subject, $bodyHtml, $unsubscribeUrl, $broadcastId = null, $queueId = null, array $attachments = [], $priority = 'bulk') {
    try {
        $safeBody = sanitizeBroadcastHtml($bodyHtml);
        $safeUnsubscribe = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');

        $footer = "<p>You're receiving this as a registered KBF-UK member. To stop receiving these emails, <a href='{$safeUnsubscribe}'>unsubscribe here</a>.</p>"
            . "<p><strong>KBF-UK</strong> | kbf-uk.org</p>";

        $text = kbfHtmlToText($safeBody) . "\n\n" .
                "---\n" .
                "You're receiving this as a registered KBF-UK member.\n" .
                "To stop receiving these emails, unsubscribe here:\n" .
                $unsubscribeUrl . "\n\n" .
                "KBF-UK | kbf-uk.org";

        return kbfSendEmail([
            'eventKey' => 'broadcast',
            'priority' => $priority === 'normal' ? 'normal' : 'bulk',
            'toEmail' => $toEmail,
            'toName' => $toName,
            'subject' => $subject,
            'html' => kbfEmailLayout($safeBody, $footer),
            'text' => $text,
            'attachments' => $attachments,
            'broadcastId' => $broadcastId,
            'relatedType' => $queueId !== null ? 'broadcast_recipient' : null,
            'relatedId' => $queueId,
        ]);
    } catch (\Throwable $e) {
        error_log("Broadcast email error: " . $e->getMessage());
        return 'failed';
    }
}
?>
