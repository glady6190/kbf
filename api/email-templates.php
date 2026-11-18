<?php
// ============================================
// KBF-UK: NOTIFICATION EVENTS AND EMAIL WORDING
// The one place for every email's original subject and wording.
//
//   KBF_NOTIFY_EVENTS     each event: group, label, when it is sent, channels
//                         that apply and their starting switches (these match
//                         the seed in database/notifications.sql and are used
//                         when that table is missing), audiences, placeholders
//   KBF_EMAIL_DEFAULTS    original subject and inner wording per TemplateKey
//                         (event key plus audience, e.g. promoted.person)
//   kbfEmailLayout()      the KBF email layout (header, colours, footer) that
//                         wraps every email; it is not editable
//
// Only the inner wording is editable (EmailTemplates). Stored HTML is cleaned
// by kbfCleanEmailHtml(). Placeholders in curly brackets are replaced when
// sending, with escaped values. A paragraph, heading or list item whose
// placeholders are all empty is left out (e.g. "Reason: {reason}" when no
// reason was given).
// ============================================

require_once __DIR__ . '/config-helpers.php';

// Files attached to emails: blocked from the web by storage/.htaccess
const KBF_ATTACHMENT_DIR = 'storage/email-attachments';
// Attachments in total per template (and per broadcast):
// Site settings upload_mb_email_attachments, 5 MB by default
function kbfAttachmentMaxBytes() {
    return kbfUploadLimitBytes('upload_mb_email_attachments', 5);
}
const KBF_ATTACHMENT_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];

const KBF_AUDIENCE_LABELS = [
    'person' => 'To the person',
    'organiser' => 'To the organiser',
    'admins' => 'To admins',
];

// ============================================
// EVENTS
// channels: [applies, enabled] for person, organiser, admins and news
// audiences: TemplateKey suffix => tab label
// ============================================
const KBF_NOTIFY_EVENTS = [
    // ---------- Players ----------
    'registration_received' => [
        'group' => 'Players', 'label' => 'Application received', 'sort' => 10, 'priority' => 'normal',
        'description' => 'Sent when someone submits the registration form.',
        'person' => [1, 1], 'organiser' => [0, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['person' => 'To the applicant'],
        'placeholders' => ['first_name', 'full_name', 'site_link'],
    ],
    'registration_approved' => [
        'group' => 'Players', 'label' => 'Registration approved', 'sort' => 20, 'priority' => 'important',
        'description' => 'Sent when an admin approves a registration. Includes the KBF ID.',
        'person' => [1, 1], 'organiser' => [0, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['person' => 'To the player'],
        'placeholders' => ['first_name', 'full_name', 'kbf_id', 'grade', 'whatsapp_link', 'site_link'],
    ],
    'registration_rejected' => [
        'group' => 'Players', 'label' => 'Registration rejected', 'sort' => 30, 'priority' => 'important',
        'description' => 'Sent when an admin rejects a registration.',
        'person' => [1, 1], 'organiser' => [0, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['person' => 'To the applicant'],
        'placeholders' => ['first_name', 'full_name', 'reason', 'site_link'],
    ],
    'registration_waiting' => [
        'group' => 'Players', 'label' => 'New registration waiting', 'sort' => 40, 'priority' => 'normal',
        'description' => 'Sent to admins when a new registration is waiting for approval.',
        'person' => [0, 0], 'organiser' => [0, 0], 'admins' => [1, 0], 'news' => [0, 0],
        'adminPermission' => 'players.approve_registrations', 'locked' => 0,
        'audiences' => ['admins' => 'To admins'],
        'placeholders' => ['first_name', 'full_name', 'grade', 'location', 'review_link', 'site_link'],
    ],
    'player_added' => [
        'group' => 'Players', 'label' => 'Player added by an admin', 'sort' => 50, 'priority' => 'normal',
        'description' => 'Sent when an admin adds a player, with their KBF ID.',
        'person' => [1, 0], 'organiser' => [0, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['person' => 'To the player'],
        'placeholders' => ['first_name', 'full_name', 'kbf_id', 'grade', 'whatsapp_link', 'site_link'],
    ],
    'promoted' => [
        'group' => 'Players', 'label' => 'Promoted to a higher grade', 'sort' => 60, 'priority' => 'normal',
        'description' => 'Sent when an admin moves a player up a grade.',
        'person' => [1, 1], 'organiser' => [0, 0], 'admins' => [1, 0], 'news' => [1, 1],
        'adminPermission' => 'players.change_grades', 'locked' => 0,
        'audiences' => ['person' => 'To the player', 'admins' => 'To admins'],
        'placeholders' => ['first_name', 'full_name', 'kbf_id', 'old_grade', 'new_grade', 'reason', 'site_link'],
    ],
    'grade_changed' => [
        'group' => 'Players', 'label' => 'Grade changed (not a promotion)', 'sort' => 70, 'priority' => 'normal',
        'description' => 'Sent when an admin changes a player\'s grade and it is not a promotion.',
        'person' => [1, 0], 'organiser' => [0, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['person' => 'To the player'],
        'placeholders' => ['first_name', 'full_name', 'kbf_id', 'old_grade', 'new_grade', 'reason', 'site_link'],
    ],
    'eligible_for_promotion' => [
        'group' => 'Players', 'label' => 'Eligible for promotion', 'sort' => 80, 'priority' => 'normal',
        'description' => 'Sent when a player first becomes eligible for promotion.',
        'person' => [1, 0], 'organiser' => [0, 0], 'admins' => [1, 0], 'news' => [0, 0],
        'adminPermission' => 'players.change_grades', 'locked' => 0,
        'audiences' => ['person' => 'To the player', 'admins' => 'To admins'],
        'placeholders' => ['first_name', 'full_name', 'kbf_id', 'grade', 'points', 'review_link', 'site_link'],
    ],
    // ---------- Tournaments and results ----------
    'tournament_submitted' => [
        'group' => 'Tournaments and results', 'label' => 'Tournament submitted for approval', 'sort' => 110, 'priority' => 'normal',
        'description' => 'Sent to admins when an organiser submits a tournament.',
        'person' => [0, 0], 'organiser' => [0, 0], 'admins' => [1, 0], 'news' => [0, 0],
        'adminPermission' => 'tournaments.approve', 'locked' => 0,
        'audiences' => ['admins' => 'To admins'],
        'placeholders' => ['tournament_name', 'tournament_date', 'organiser_name', 'review_link', 'site_link'],
    ],
    'tournament_approved' => [
        'group' => 'Tournaments and results', 'label' => 'Tournament approved', 'sort' => 120, 'priority' => 'normal',
        'description' => 'Sent to the organiser when their tournament is approved.',
        'person' => [0, 0], 'organiser' => [1, 1], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['organiser' => 'To the organiser'],
        'placeholders' => ['first_name', 'full_name', 'tournament_name', 'tournament_date', 'site_link'],
    ],
    'tournament_rejected' => [
        'group' => 'Tournaments and results', 'label' => 'Tournament rejected', 'sort' => 130, 'priority' => 'normal',
        'description' => 'Sent to the organiser when their tournament is rejected.',
        'person' => [0, 0], 'organiser' => [1, 1], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['organiser' => 'To the organiser'],
        'placeholders' => ['first_name', 'full_name', 'tournament_name', 'tournament_date', 'reason', 'site_link'],
    ],
    'results_submitted' => [
        'group' => 'Tournaments and results', 'label' => 'Results submitted for approval', 'sort' => 140, 'priority' => 'normal',
        'description' => 'Sent to admins when an organiser submits match results.',
        'person' => [0, 0], 'organiser' => [0, 0], 'admins' => [1, 0], 'news' => [0, 0],
        'adminPermission' => 'tournaments.approve', 'locked' => 0,
        'audiences' => ['admins' => 'To admins'],
        'placeholders' => ['tournament_name', 'tournament_date', 'organiser_name', 'count', 'review_link', 'site_link'],
    ],
    'results_approved' => [
        'group' => 'Tournaments and results', 'label' => 'Results approved', 'sort' => 150, 'priority' => 'normal',
        'description' => 'Sent to the organiser when their results are approved.',
        'person' => [0, 0], 'organiser' => [1, 1], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['organiser' => 'To the organiser'],
        'placeholders' => ['first_name', 'full_name', 'tournament_name', 'tournament_date', 'site_link'],
    ],
    'results_rejected' => [
        'group' => 'Tournaments and results', 'label' => 'Results rejected', 'sort' => 160, 'priority' => 'normal',
        'description' => 'Sent to the organiser when their results are rejected.',
        'person' => [0, 0], 'organiser' => [1, 1], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['organiser' => 'To the organiser'],
        'placeholders' => ['first_name', 'full_name', 'tournament_name', 'tournament_date', 'reason', 'site_link'],
    ],
    'results_overdue' => [
        'group' => 'Tournaments and results', 'label' => 'Results not yet submitted', 'sort' => 170, 'priority' => 'normal',
        'description' => 'Reminds the organiser when a tournament has taken place but its results have not been submitted. Sent by an admin from the Tournaments page, or once automatically.',
        'person' => [0, 0], 'organiser' => [1, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['organiser' => 'To the organiser'],
        'placeholders' => ['first_name', 'full_name', 'tournament_name', 'tournament_date', 'submit_link', 'site_link'],
    ],
    // ---------- Staff and system ----------
    'password_reset_requested' => [
        'group' => 'Staff and system', 'label' => 'Password reset requested', 'sort' => 210, 'priority' => 'important',
        'description' => 'Sent when a staff member asks to reset their password, or a Super Admin sends a reset.',
        'person' => [1, 1], 'organiser' => [0, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 1,
        'audiences' => ['person' => 'To the staff member'],
        'placeholders' => ['first_name', 'full_name', 'reset_link', 'site_link'],
    ],
    'password_reset_completed' => [
        'group' => 'Staff and system', 'label' => 'Password reset completed', 'sort' => 220, 'priority' => 'important',
        'description' => 'Sent when a password has been reset.',
        'person' => [1, 1], 'organiser' => [0, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 1,
        'audiences' => ['person' => 'To the staff member'],
        'placeholders' => ['first_name', 'full_name', 'site_link'],
    ],
    'staff_access_changed' => [
        'group' => 'Staff and system', 'label' => 'Staff access changed', 'sort' => 230, 'priority' => 'normal',
        'description' => 'Sent when a Super Admin changes someone\'s labels or permissions.',
        'person' => [1, 0], 'organiser' => [0, 0], 'admins' => [0, 0], 'news' => [0, 0],
        'adminPermission' => null, 'locked' => 0,
        'audiences' => ['person' => 'To the staff member'],
        'placeholders' => ['first_name', 'full_name', 'access_list', 'login_link', 'site_link'],
    ],
    'season_reset_completed' => [
        'group' => 'Staff and system', 'label' => 'Season reset completed', 'sort' => 240, 'priority' => 'normal',
        'description' => 'Sent to admins when the season points reset has run.',
        'person' => [0, 0], 'organiser' => [0, 0], 'admins' => [1, 0], 'news' => [0, 0],
        'adminPermission' => 'control.grading', 'locked' => 0,
        'audiences' => ['admins' => 'To admins'],
        'placeholders' => ['term', 'count', 'floored_count', 'zeroed_count', 'skipped_count', 'floor_value', 'site_link'],
    ],
    'broadcast_finished' => [
        'group' => 'Staff and system', 'label' => 'Broadcast finished', 'sort' => 250, 'priority' => 'normal',
        'description' => 'Sent to admins when a broadcast has finished sending.',
        'person' => [0, 0], 'organiser' => [0, 0], 'admins' => [1, 0], 'news' => [0, 0],
        'adminPermission' => 'content.broadcasts', 'locked' => 0,
        'audiences' => ['admins' => 'To admins'],
        'placeholders' => ['broadcast_subject', 'count', 'sent_count', 'failed_count', 'site_link'],
    ],
];

const KBF_NOTIFY_GROUPS = ['Players', 'Tournaments and results', 'Staff and system'];

// Placeholders every email can use, on top of each event's own list
const KBF_COMMON_PLACEHOLDERS = ['site_link', 'contact_email', 'security_email'];

// An event's placeholders, including the common ones
function kbfEventPlaceholders($eventKey) {
    $own = KBF_NOTIFY_EVENTS[$eventKey]['placeholders'] ?? [];
    return array_values(array_unique(array_merge($own, KBF_COMMON_PLACEHOLDERS)));
}

// Contact addresses from Site settings (today's addresses as fallbacks)
function kbfContactEmail() {
    $v = trim((string) kbfSetting('contact_email', 'admin@kbf-uk.org'));
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : 'admin@kbf-uk.org';
}

function kbfSecurityEmail() {
    $v = trim((string) kbfSetting('security_email', 'security@kbf-uk.org'));
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : 'security@kbf-uk.org';
}

// ============================================
// PLACEHOLDERS: description and sample value (preview and test emails)
// ============================================
const KBF_PLACEHOLDERS = [
    'first_name' => ['First name of the person the email is about', 'Priya'],
    'full_name' => ['Full name of the person the email is about', 'Priya Shah'],
    'kbf_id' => ['KBF membership ID', 'KBFUK123'],
    'grade' => ['Grade', 'C'],
    'old_grade' => ['Grade before the change', 'C'],
    'new_grade' => ['Grade after the change', 'C+'],
    'points' => ['Points in the current grade', '320'],
    'location' => ['Location given on the registration', 'Leeds'],
    'reason' => ['Reason the admin gave (left out when there is none)', 'Please add the venue address.'],
    'tournament_name' => ['Tournament name', 'KBF Winter Open'],
    'tournament_date' => ['Tournament date', 'Saturday, 12 December 2026'],
    'organiser_name' => ['Organiser of the tournament', 'Leeds Badminton Club'],
    'submit_link' => ['Page where the organiser submits results for this tournament', 'https://kbf-uk.org/clubadmin/submit-results?id=123'],
    'reset_link' => ['Password reset link (never stored)', 'https://kbf-uk.org/reset-password.html?token=sample'],
    'access_list' => ['What the person can now do in the admin area', 'Approve player registrations, Change player grades'],
    'login_link' => ['Admin sign-in page', 'https://kbf-uk.org/login'],
    'review_link' => ['Admin page where this can be dealt with', 'https://kbf-uk.org/admin/approvals'],
    'count' => ['How many (players, matches or recipients)', '12'],
    'term' => ['Season that was reset', '2027-H1'],
    'floored_count' => ['Players lowered to the floor value', '8'],
    'zeroed_count' => ['Players set to 0 points', '40'],
    'skipped_count' => ['Eligible players left unchanged', '3'],
    'floor_value' => ['Floor value for points', '150'],
    'broadcast_subject' => ['Subject of the broadcast', 'Summer tournament entries now open'],
    'sent_count' => ['Emails sent', '118'],
    'failed_count' => ['Emails that failed', '2'],
    'site_link' => ['Address of the KBF-UK website', 'https://kbf-uk.org'],
    'contact_email' => ['General KBF-UK email address (Site settings)', 'admin@kbf-uk.org'],
    'security_email' => ['Security email address (Site settings)', 'security@kbf-uk.org'],
    'whatsapp_link' => ['KBF-UK WhatsApp group link', 'https://chat.whatsapp.com/'],
];

// ============================================
// ORIGINAL WORDING (inner content only)
// ============================================
const KBF_EMAIL_DEFAULTS = [
    'registration_received.person' => [
        'subject' => 'KBF-UK — Application Received',
        'body' => '<h2>Application Received</h2>'
            . '<p>Hi {first_name},</p>'
            . '<p>Thank you for applying for KBF-UK membership. We\'ve received your application and it is now <strong>pending review</strong> by our admin team.</p>'
            . '<p>You don\'t need to do anything further right now. We\'ll email you again once a decision has been made — and if approved, that email will include your membership ID.</p>'
            . '<p>Thanks for your patience.</p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'registration_approved.person' => [
        'subject' => 'Registration Approved - KBF-UK',
        'body' => '<h2>Congratulations, {first_name}!</h2>'
            . '<p>Your registration has been <strong>APPROVED</strong> by our admin team.</p>'
            . '<h3>Your Membership ID: {kbf_id}</h3>'
            . '<p><strong>IMPORTANT:</strong> Save this membership ID for your records.</p>'
            . '<p>You will need it for match entries and tournament registrations.</p>'
            . '<h3>Join Our WhatsApp Group</h3>'
            . '<p>Stay connected with the KBF-UK community! Join our official WhatsApp group for the latest news, tournament updates and announcements.</p>'
            . '<p><a href="{whatsapp_link}">Join KBF-UK WhatsApp Group</a></p>'
            . '<p>If you have any questions, please don\'t hesitate to contact us.</p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'registration_rejected.person' => [
        'subject' => 'Registration Update - KBF-UK',
        'body' => '<h2>Dear {first_name},</h2>'
            . '<p>Thank you for your interest in joining KBF-UK.</p>'
            . '<p>Unfortunately, we are unable to approve your registration at this time.</p>'
            . '<p><strong>Reason:</strong> {reason}</p>'
            . '<p>If you believe this was an error or would like to discuss this decision, please feel free to contact us.</p>'
            . '<p><a href="{site_link}/register.html">Register Again</a></p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'registration_waiting.admins' => [
        'subject' => 'New registration waiting - KBF-UK',
        'body' => '<h2>New registration waiting</h2>'
            . '<p>{full_name} has applied for KBF-UK membership and is waiting for approval.</p>'
            . '<p><strong>Grade applied for:</strong> {grade}<br><strong>Location:</strong> {location}</p>'
            . '<p><a href="{review_link}">Review registrations</a></p>',
    ],
    'player_added.person' => [
        'subject' => 'Welcome to KBF-UK - your membership ID',
        'body' => '<h2>Welcome, {first_name}!</h2>'
            . '<p>You have been added as a KBF-UK player by our admin team.</p>'
            . '<h3>Your Membership ID: {kbf_id}</h3>'
            . '<p><strong>IMPORTANT:</strong> Save this membership ID for your records.</p>'
            . '<p>You will need it for match entries and tournament registrations.</p>'
            . '<p><strong>Your grade:</strong> {grade}</p>'
            . '<h3>Join Our WhatsApp Group</h3>'
            . '<p>Stay connected with the KBF-UK community! Join our official WhatsApp group for the latest news, tournament updates and announcements.</p>'
            . '<p><a href="{whatsapp_link}">Join KBF-UK WhatsApp Group</a></p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'promoted.person' => [
        'subject' => 'Congratulations on Your Promotion - KBF-UK',
        'body' => '<h2>Well done, {first_name}!</h2>'
            . '<p>We\'re delighted to let you know that you\'ve been <strong>promoted</strong> to a higher grade.</p>'
            . '<h3>Your New Grade: {old_grade} → {new_grade}</h3>'
            . '<p>This promotion reflects your hard work and performance on court. Keep it up!</p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'promoted.admins' => [
        'subject' => 'Player promoted: {full_name}',
        'body' => '<h2>Player promoted</h2>'
            . '<p>{full_name} ({kbf_id}) has been promoted from {old_grade} to {new_grade}.</p>'
            . '<p><strong>Reason:</strong> {reason}</p>',
    ],
    'grade_changed.person' => [
        'subject' => 'Your grade has changed - KBF-UK',
        'body' => '<h2>Hello {first_name},</h2>'
            . '<p>Your KBF-UK grade has been changed from <strong>{old_grade}</strong> to <strong>{new_grade}</strong>.</p>'
            . '<p><strong>Reason:</strong> {reason}</p>'
            . '<p>If you have any questions, please contact the admin team.</p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'eligible_for_promotion.person' => [
        'subject' => 'You are eligible for promotion - KBF-UK',
        'body' => '<h2>Well done, {first_name}!</h2>'
            . '<p>You now have <strong>{points}</strong> points in grade {grade}, which makes you eligible for promotion.</p>'
            . '<p>The admin team will review your grade.</p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'eligible_for_promotion.admins' => [
        'subject' => 'Eligible for promotion: {full_name}',
        'body' => '<h2>Eligible for promotion</h2>'
            . '<p>{full_name} ({kbf_id}) now has {points} points in grade {grade} and is eligible for promotion.</p>'
            . '<p><a href="{review_link}">Open grading</a></p>',
    ],
    'tournament_submitted.admins' => [
        'subject' => 'Tournament waiting for approval: {tournament_name}',
        'body' => '<h2>Tournament waiting for approval</h2>'
            . '<p><strong>{tournament_name}</strong> on {tournament_date} has been submitted by {organiser_name}.</p>'
            . '<p><a href="{review_link}">Review approvals</a></p>',
    ],
    'tournament_approved.organiser' => [
        'subject' => 'Tournament Approved - KBF-UK',
        'body' => '<h2>Great News, {first_name}!</h2>'
            . '<p>Your tournament has been <strong>APPROVED</strong> by the KBF-UK admin team.</p>'
            . '<h3>{tournament_name}</h3>'
            . '<p>{tournament_date}</p>'
            . '<p><strong>Next Steps:</strong></p>'
            . '<ul><li>Your tournament is now live on the KBF-UK website</li>'
            . '<li>Players can now view tournament details</li>'
            . '<li>You can submit match results after the tournament</li></ul>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'tournament_rejected.organiser' => [
        'subject' => 'Tournament Update - KBF-UK',
        'body' => '<h2>Dear {first_name},</h2>'
            . '<p>Unfortunately, your tournament <strong>{tournament_name}</strong> has not been approved at this time.</p>'
            . '<p><strong>Reason:</strong> {reason}</p>'
            . '<p>If you have any questions or would like to discuss this decision, please contact the admin team.</p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'results_submitted.admins' => [
        'subject' => 'Results waiting for approval: {tournament_name}',
        'body' => '<h2>Results waiting for approval</h2>'
            . '<p>{organiser_name} has submitted {count} match results for <strong>{tournament_name}</strong> ({tournament_date}).</p>'
            . '<p><a href="{review_link}">Review approvals</a></p>',
    ],
    'results_approved.organiser' => [
        'subject' => 'Match Results Approved - KBF-UK',
        'body' => '<h2>Good News, {first_name}!</h2>'
            . '<p>The match results you submitted for <strong>{tournament_name}</strong> have been approved.</p>'
            . '<ul><li>Points have been awarded to players</li>'
            . '<li>Rankings have been updated</li>'
            . '<li>Tournament marked as completed</li></ul>'
            . '<p>Thank you for organizing this tournament and submitting the results promptly.</p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'results_rejected.organiser' => [
        'subject' => 'Match Results Update - KBF-UK',
        'body' => '<h2>Dear {first_name},</h2>'
            . '<p>The match results you submitted for <strong>{tournament_name}</strong> have not been approved.</p>'
            . '<p><strong>Reason:</strong> {reason}</p>'
            . '<p>Please review the results and resubmit with corrections if needed. Contact the admin team if you have questions.</p>'
            . '<p>Best regards,<br><strong>KBF-UK Admin Team</strong></p>',
    ],
    'results_overdue.organiser' => [
        'subject' => 'Results needed: {tournament_name}',
        'body' => '<p>Hi {first_name},</p>'
            . '<p>{tournament_name} took place on {tournament_date}, but its results have not been submitted yet. Players receive their KBF points only once the results are submitted and approved.</p>'
            . '<p>Please submit them here: <a href="{submit_link}">{submit_link}</a></p>'
            . '<p>If the tournament did not go ahead, please let us know at {contact_email}.</p>',
    ],
    'password_reset_requested.person' => [
        'subject' => 'Password Reset Request - KBF-UK',
        'body' => '<h2>Password Reset Request</h2>'
            . '<p>Hello {full_name},</p>'
            . '<p>We received a request to reset your password for your KBF-UK account. If you made this request, click the link below to reset your password:</p>'
            . '<p><a href="{reset_link}">Reset Password</a></p>'
            . '<p><strong>This link expires in 1 hour</strong><br><strong>One-time use only</strong></p>'
            . '<p>If the link doesn\'t work, copy and paste this address into your browser:</p>'
            . '<p>{reset_link}</p>'
            . '<p>If you didn\'t request this password reset, please ignore this email. Your password will remain unchanged.</p>'
            . '<p>For security reasons, this link will expire in 1 hour and can only be used once.</p>',
    ],
    'password_reset_completed.person' => [
        'subject' => 'Password Reset Successful - KBF-UK',
        'body' => '<h2>Password Reset Successful</h2>'
            . '<p>Hello {full_name},</p>'
            . '<p>Your password has been successfully reset. You can now log in to your account using your new password.</p>'
            . '<p>If you did not make this change, please contact us immediately at <strong>{security_email}</strong></p>'
            . '<p><strong>Security Notice:</strong> If you did not reset your password, your account may be compromised.</p>'
            . '<p>For your security, we recommend:</p>'
            . '<ul><li>Using a strong, unique password</li>'
            . '<li>Never sharing your password with anyone</li>'
            . '<li>Logging out when finished</li></ul>',
    ],
    'staff_access_changed.person' => [
        'subject' => 'Your KBF-UK admin access has changed',
        'body' => '<h2>Hello {first_name},</h2>'
            . '<p>Your access to the KBF-UK admin area has been changed.</p>'
            . '<p><strong>You can now:</strong> {access_list}</p>'
            . '<p><a href="{login_link}">Sign in to the admin area</a></p>'
            . '<p>If you did not expect this change, please contact a Super Admin.</p>',
    ],
    'season_reset_completed.admins' => [
        'subject' => 'Season reset completed: {term}',
        'body' => '<h2>Season reset completed</h2>'
            . '<p>The points reset for {term} has finished.</p>'
            . '<ul><li>Players reset: {count}</li>'
            . '<li>Lowered to {floor_value}: {floored_count}</li>'
            . '<li>Set to 0: {zeroed_count}</li>'
            . '<li>Eligible for promotion, left unchanged: {skipped_count}</li></ul>',
    ],
    'broadcast_finished.admins' => [
        'subject' => 'Broadcast finished: {broadcast_subject}',
        'body' => '<h2>Broadcast finished</h2>'
            . '<p>The broadcast <strong>{broadcast_subject}</strong> has finished sending.</p>'
            . '<ul><li>Recipients: {count}</li>'
            . '<li>Sent: {sent_count}</li>'
            . '<li>Failed: {failed_count}</li></ul>',
    ],
];

// ============================================
// LAYOUT (not editable)
// ============================================
const KBF_EMAIL_FOOTER_DEFAULT = '<p><strong>KBF-UK</strong> | kbf-uk.org</p>'
    . '<p>This is an automated email. Please do not reply directly to this message.</p>';

// Wrap cleaned inner content in the KBF email layout.
// $footerHtml is trusted HTML built in code (e.g. the broadcast unsubscribe line).
function kbfEmailLayout($innerHtml, $footerHtml = null) {
    $footer = $footerHtml === null ? KBF_EMAIL_FOOTER_DEFAULT : $footerHtml;
    return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background-color: #0f172a; color: #f1f5f9; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background-color: #1e293b; border: 4px solid #374151; }
        .header { background-color: #10b981; padding: 30px; text-align: center; border-bottom: 4px solid #374151; }
        .header h1 { margin: 0; color: #0f172a; font-size: 24px; font-weight: 900; text-transform: uppercase; letter-spacing: 2px; }
        .content { padding: 40px 30px; line-height: 1.6; color: #cbd5e1; font-weight: 600; }
        .content h2 { color: #10b981; font-size: 20px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 20px; }
        .content h3 { color: #f1f5f9; font-size: 17px; font-weight: 900; margin: 25px 0 10px; }
        .content p { line-height: 1.6; color: #cbd5e1; margin: 15px 0; }
        .content ul, .content ol { color: #cbd5e1; padding-left: 22px; }
        .content li { margin: 6px 0; }
        .content a { color: #10b981; font-weight: 900; }
        .content strong, .content b { color: #f1f5f9; }
        .footer { background-color: #0f172a; padding: 20px 30px; text-align: center; border-top: 4px solid #374151; font-size: 12px; color: #64748b; }
        .footer p { margin: 5px 0; color: #64748b; }
        .footer a { color: #10b981; }
        @media only screen and (max-width: 600px) {
            .container { margin: 0; border: none; }
            .content { padding: 30px 20px; }
        }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'><h1>KBF-UK</h1></div>
        <div class='content'>
" . $innerHtml . "
        </div>
        <div class='footer'>
            " . $footer . "
        </div>
    </div>
</body>
</html>";
}

// ============================================
// SITE LINKS
// ============================================
// Every link inside an email is built from site_base_url (Site settings)
function kbfSiteLink($pdo) {
    $url = rtrim((string) kbfSetting('site_base_url', 'https://kbf-uk.org'), '/');
    return preg_match('#^https?://#i', $url) ? $url : 'https://kbf-uk.org';
}

function kbfWhatsappLink($pdo) {
    $url = trim((string) kbfSetting('whatsapp_link', 'https://chat.whatsapp.com/ItJTbUxRi9R56LFmkT99dp?s=sh&p=a&ilr=1'));
    return preg_match('#^https://#i', $url) ? $url : '';
}

// ============================================
// HTML CLEANING (allowlist)
// p, br, b, strong, i, em, h2, h3, ul, ol, li and a with an http, https or
// mailto address (or a placeholder such as {reset_link}) only.
// ============================================
const KBF_EMAIL_ALLOWED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'h2', 'h3', 'ul', 'ol', 'li', 'a'];

function kbfCleanHref($href) {
    $href = trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || preg_match('/[\s"\'<>`\\\\]/', $href)) {
        return null;
    }
    if (preg_match('#^(https?://|mailto:)#i', $href)) {
        return $href;
    }
    if (preg_match('#^\{[a-z_]+\}[A-Za-z0-9/._?=&%\#~+-]*$#', $href)) {
        return $href;
    }
    return null;
}

function kbfCleanEmailHtml($html) {
    $html = (string) $html;
    if (trim($html) === '') {
        return '';
    }
    if (!class_exists('DOMDocument')) {
        return kbfCleanEmailHtmlFallback($html);
    }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?><html><body><div>' . $html . '</div></body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body || !$body->firstChild) {
        return '';
    }
    $out = kbfCleanEmailChildren($body->firstChild);
    // Tidy empty paragraphs left by editors
    $out = preg_replace('#<p>(\s|<br>)*</p>#', '', $out);
    return trim($out);
}

function kbfCleanEmailChildren(DOMNode $node) {
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $out .= htmlspecialchars($child->nodeValue, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            continue;
        }
        if (!($child instanceof DOMElement)) {
            continue;
        }
        $tag = strtolower($child->nodeName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'template', 'noscript', 'svg', 'math', 'img', 'video', 'audio', 'form', 'input', 'button', 'select', 'textarea'], true)) {
            continue;
        }
        if ($tag === 'br') {
            $out .= '<br>';
            continue;
        }
        // Editors produce div for new lines; headings map to the two allowed levels
        if ($tag === 'div') {
            $hasBlock = false;
            foreach ($child->childNodes as $grand) {
                if ($grand instanceof DOMElement && in_array(strtolower($grand->nodeName), ['p', 'div', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                    $hasBlock = true;
                    break;
                }
            }
            $inner = kbfCleanEmailChildren($child);
            $out .= $hasBlock ? $inner : ('<p>' . $inner . '</p>');
            continue;
        }
        if ($tag === 'h1') { $tag = 'h2'; }
        if (in_array($tag, ['h4', 'h5', 'h6'], true)) { $tag = 'h3'; }

        $inner = kbfCleanEmailChildren($child);
        if (!in_array($tag, KBF_EMAIL_ALLOWED_TAGS, true)) {
            $out .= $inner; // unwrap anything else, keep its text
            continue;
        }
        if ($tag === 'a') {
            $href = kbfCleanHref($child->getAttribute('href'));
            $out .= $href === null ? $inner
                : '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $inner . '</a>';
            continue;
        }
        $out .= '<' . $tag . '>' . $inner . '</' . $tag . '>';
    }
    return $out;
}

// Used only when the PHP DOM extension is missing
function kbfCleanEmailHtmlFallback($html) {
    $html = preg_replace('#<(script|style|iframe|object|embed|svg|math)\b[^>]*>.*?</\1>#is', '', $html);
    $html = strip_tags($html, '<p><br><b><strong><i><em><h2><h3><ul><ol><li><a>');
    // Remove every attribute except a safe href on links
    $html = preg_replace_callback('#<([a-z0-9]+)(\s[^>]*)?>#i', function ($m) {
        $tag = strtolower($m[1]);
        if ($tag === 'a' && isset($m[2]) && preg_match('#href\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $m[2], $h)) {
            $href = kbfCleanHref($h[2] !== '' ? $h[2] : ($h[3] ?? ''));
            return $href === null ? '<a>' : '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
        }
        return '<' . $tag . '>';
    }, $html);
    return trim($html);
}

// ============================================
// PLAIN TEXT made from the HTML
// ============================================
function kbfHtmlToText($html) {
    $text = (string) $html;
    $text = preg_replace_callback('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', function ($m) {
        $label = trim(strip_tags($m[2]));
        $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        return ($label === '' || $label === $url) ? $url : ($label . ' (' . $url . ')');
    }, $text);
    $text = preg_replace('#<br\s*/?>#i', "\n", $text);
    $text = preg_replace('#<li[^>]*>#i', '- ', $text);
    $text = preg_replace('#</(p|h1|h2|h3|ul|ol|div)>#i', "\n\n", $text);
    $text = preg_replace('#</li>#i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+\n/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

// ============================================
// PLACEHOLDERS
// ============================================

// Placeholder names used in a text, e.g. ['first_name', 'kbf_id']
function kbfPlaceholdersIn($text) {
    preg_match_all('/\{([a-z_]+)\}/', (string) $text, $m);
    return array_values(array_unique($m[1]));
}

// Placeholders used but not offered by this event
function kbfUnknownPlaceholders($text, array $allowed) {
    return array_values(array_diff(kbfPlaceholdersIn($text), $allowed));
}

// Fill a template. $values: name => plain value (escaped here).
// Returns ['subject' => string, 'html' => inner HTML].
function kbfRenderTemplate($subject, $bodyHtml, array $values) {
    // A known placeholder without a value counts as empty. An unknown one
    // (e.g. a typo) is never empty: it stays visible as written.
    foreach (array_keys(KBF_PLACEHOLDERS) as $known) {
        if (!array_key_exists($known, $values)) {
            $values[$known] = '';
        }
    }
    $isEmpty = function ($name) use ($values) {
        return array_key_exists($name, $values) && trim((string) $values[$name]) === '';
    };

    // Leave out a block whose placeholders are all empty
    $bodyHtml = preg_replace_callback('#<(p|li|h2|h3)>(.*?)</\1>#is', function ($m) use ($isEmpty) {
        $names = kbfPlaceholdersIn($m[2]);
        if (!$names) {
            return $m[0];
        }
        foreach ($names as $name) {
            if (!$isEmpty($name)) {
                return $m[0];
            }
        }
        return '';
    }, (string) $bodyHtml);

    $html = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values) {
        if (!array_key_exists($m[1], $values)) {
            return $m[0];
        }
        return htmlspecialchars((string) $values[$m[1]], ENT_QUOTES, 'UTF-8');
    }, $bodyHtml);

    $subjectOut = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values) {
        return array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0];
    }, (string) $subject);
    $subjectOut = trim(preg_replace('/[\r\n\t]+/', ' ', $subjectOut));

    return ['subject' => $subjectOut, 'html' => $html];
}

// Sample values for preview and test emails
function kbfSampleValues($pdo) {
    $values = [];
    foreach (KBF_PLACEHOLDERS as $name => $info) {
        $values[$name] = $info[1];
    }
    $values['site_link'] = kbfSiteLink($pdo);
    $values['login_link'] = kbfSiteLink($pdo) . '/login';
    $values['review_link'] = kbfSiteLink($pdo) . '/admin/approvals';
    $values['submit_link'] = kbfSiteLink($pdo) . '/clubadmin/submit-results?id=123';
    $values['contact_email'] = kbfContactEmail();
    $values['security_email'] = kbfSecurityEmail();
    $wa = kbfWhatsappLink($pdo);
    if ($wa !== '') {
        $values['whatsapp_link'] = $wa;
    }
    return $values;
}

// ============================================
// TEMPLATES
// ============================================
function kbfTemplateKey($eventKey, $audience) {
    return $eventKey . '.' . $audience;
}

// ['subject', 'bodyHtml', 'custom' => bool, 'updatedDate' => string|null]
function kbfGetTemplate($pdo, $templateKey) {
    $default = KBF_EMAIL_DEFAULTS[$templateKey] ?? ['subject' => 'KBF-UK', 'body' => ''];
    try {
        $stmt = $pdo->prepare("SELECT Subject, BodyHtml, UpdatedDate FROM EmailTemplates WHERE TemplateKey = ?");
        $stmt->execute([$templateKey]);
        $row = $stmt->fetch();
        if ($row) {
            return ['subject' => $row['Subject'], 'bodyHtml' => $row['BodyHtml'], 'custom' => true, 'updatedDate' => $row['UpdatedDate']];
        }
    } catch (PDOException $e) {
        // notifications.sql not run yet: the original wording is used
    }
    return ['subject' => $default['subject'], 'bodyHtml' => $default['body'], 'custom' => false, 'updatedDate' => null];
}

// Attachments of a template: [{id, name, file, mime, size}]
function kbfTemplateAttachments($pdo, $templateKey) {
    try {
        $stmt = $pdo->prepare("
            SELECT Id, OriginalName, StoredName, MimeType, SizeBytes
            FROM TemplateAttachments WHERE TemplateKey = ? ORDER BY Id
        ");
        $stmt->execute([$templateKey]);
        return array_map(function ($r) {
            return [
                'id' => (int) $r['Id'],
                'name' => $r['OriginalName'],
                'file' => KBF_ATTACHMENT_DIR . '/' . $r['StoredName'],
                'mime' => $r['MimeType'],
                'size' => (int) $r['SizeBytes'],
            ];
        }, $stmt->fetchAll());
    } catch (PDOException $e) {
        return [];
    }
}

// ============================================
// UPLOADED FILES: real type check
// Returns ['ext' => ..., 'mime' => ...] or ['error' => message]
// ============================================
function kbfCheckAttachmentFile($tmpPath, $originalName) {
    if (!is_file($tmpPath)) {
        return ['error' => 'The file could not be read'];
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
    }
    $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));

    if (isset(KBF_ATTACHMENT_TYPES[$mime])) {
        return ['ext' => KBF_ATTACHMENT_TYPES[$mime], 'mime' => $mime];
    }
    // Some servers report a Word file as a plain zip: check what is inside
    if ($ext === 'docx' && in_array($mime, ['application/zip', 'application/octet-stream'], true) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) === true) {
            $isWord = $zip->locateName('word/document.xml') !== false;
            $zip->close();
            if ($isWord) {
                return ['ext' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            }
        }
    }
    return ['error' => 'Only PDF, JPG, PNG or Word (.docx) files can be attached'];
}

// A display name safe for email headers and the page
function kbfCleanFileName($name) {
    $name = basename(str_replace('\\', '/', (string) $name));
    $name = preg_replace('/[\x00-\x1F\x7F"<>]+/u', '', $name);
    $name = trim($name);
    if ($name === '') {
        $name = 'attachment';
    }
    return function_exists('mb_substr') ? mb_substr($name, 0, 150, 'UTF-8') : substr($name, 0, 150);
}

// Absolute folder for template and broadcast attachments (created if missing)
function kbfAttachmentFolder() {
    $dir = dirname(__DIR__) . '/' . KBF_ATTACHMENT_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}
?>
