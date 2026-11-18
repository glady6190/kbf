-- ============================================
-- KBF-UK: CONTROL CENTRE STAGE 2 - NOTIFICATIONS AND EMAIL WORDING
-- Run in phpMyAdmin (MariaDB) AFTER database/email-engine.sql.
-- Safe to run more than once. Never drops or deletes anything:
--   * CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS / INSERT IGNORE
--   * Switches, recipients and wording already saved are kept
--
-- 1. NotificationEvents     one row per event: channels, switches, priority
-- 2. NotificationRecipients the chosen admin recipients per event
-- 3. NotificationDigest     admin emails held for the nightly summary
-- 4. EmailTemplates         changed wording only (otherwise the code's wording)
-- 5. TemplateAttachments    files attached to an email
-- 6. EmailLog.AttachmentsJson, EmailBroadcasts.AttachmentsJson
-- 7. Settings: whatsapp_link
--
-- The event keys MUST match api/email-templates.php.
-- ============================================


-- --------------------------------------------
-- 1. NotificationEvents
-- For each channel: *Applies (the channel exists for this event) and
-- *Enabled (switched on). Locked events cannot be switched off.
-- AdminPermission: whose holders can be chosen as admin recipients.
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS NotificationEvents (
    EventKey               VARCHAR(60)  NOT NULL,
    GroupName              VARCHAR(60)  NOT NULL,
    Label                  VARCHAR(120) NOT NULL,
    SortOrder              INT          NOT NULL DEFAULT 0,
    Priority               ENUM('important', 'normal') NOT NULL DEFAULT 'normal',
    EmailPersonApplies     TINYINT(1)   NOT NULL DEFAULT 0,
    EmailPersonEnabled     TINYINT(1)   NOT NULL DEFAULT 0,
    EmailOrganiserApplies  TINYINT(1)   NOT NULL DEFAULT 0,
    EmailOrganiserEnabled  TINYINT(1)   NOT NULL DEFAULT 0,
    EmailAdminsApplies     TINYINT(1)   NOT NULL DEFAULT 0,
    EmailAdminsEnabled     TINYINT(1)   NOT NULL DEFAULT 0,
    NewsPostApplies        TINYINT(1)   NOT NULL DEFAULT 0,
    NewsPostEnabled        TINYINT(1)   NOT NULL DEFAULT 0,
    AdminPermission        VARCHAR(60)  NULL DEFAULT NULL,
    AdminDelivery          ENUM('immediate', 'daily') NOT NULL DEFAULT 'immediate',
    Locked                 TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (EventKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO NotificationEvents
    (EventKey, GroupName, Label, SortOrder, Priority,
     EmailPersonApplies, EmailPersonEnabled, EmailOrganiserApplies, EmailOrganiserEnabled,
     EmailAdminsApplies, EmailAdminsEnabled, NewsPostApplies, NewsPostEnabled,
     AdminPermission, AdminDelivery, Locked)
VALUES
    -- Players
    ('registration_received',    'Players', 'Application received',              10, 'normal',    1, 1, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('registration_approved',    'Players', 'Registration approved',             20, 'important', 1, 1, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('registration_rejected',    'Players', 'Registration rejected',             30, 'important', 1, 1, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('registration_waiting',     'Players', 'New registration waiting',          40, 'normal',    0, 0, 0, 0, 1, 0, 0, 0, 'players.approve_registrations', 'immediate', 0),
    ('player_added',             'Players', 'Player added by an admin',          50, 'normal',    1, 0, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('promoted',                 'Players', 'Promoted to a higher grade',        60, 'normal',    1, 1, 0, 0, 1, 0, 1, 1, 'players.change_grades', 'immediate', 0),
    ('grade_changed',            'Players', 'Grade changed (not a promotion)',   70, 'normal',    1, 0, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('eligible_for_promotion',   'Players', 'Eligible for promotion',            80, 'normal',    1, 0, 0, 0, 1, 0, 0, 0, 'players.change_grades', 'immediate', 0),
    -- Tournaments and results
    ('tournament_submitted',     'Tournaments and results', 'Tournament submitted for approval', 110, 'normal', 0, 0, 0, 0, 1, 0, 0, 0, 'tournaments.approve', 'immediate', 0),
    ('tournament_approved',      'Tournaments and results', 'Tournament approved',               120, 'normal', 0, 0, 1, 1, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('tournament_rejected',      'Tournaments and results', 'Tournament rejected',               130, 'normal', 0, 0, 1, 1, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('results_submitted',        'Tournaments and results', 'Results submitted for approval',    140, 'normal', 0, 0, 0, 0, 1, 0, 0, 0, 'tournaments.approve', 'immediate', 0),
    ('results_approved',         'Tournaments and results', 'Results approved',                  150, 'normal', 0, 0, 1, 1, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('results_rejected',         'Tournaments and results', 'Results rejected',                  160, 'normal', 0, 0, 1, 1, 0, 0, 0, 0, NULL, 'immediate', 0),
    -- Staff and system
    ('password_reset_requested', 'Staff and system', 'Password reset requested', 210, 'important', 1, 1, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 1),
    ('password_reset_completed', 'Staff and system', 'Password reset completed', 220, 'important', 1, 1, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 1),
    ('staff_access_changed',     'Staff and system', 'Staff access changed',     230, 'normal',    1, 0, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 0),
    ('season_reset_completed',   'Staff and system', 'Season reset completed',   240, 'normal',    0, 0, 0, 0, 1, 0, 0, 0, 'control.grading', 'immediate', 0),
    ('broadcast_finished',       'Staff and system', 'Broadcast finished',       250, 'normal',    0, 0, 0, 0, 1, 0, 0, 0, 'content.broadcasts', 'immediate', 0);


-- --------------------------------------------
-- 2. NotificationRecipients (starts empty: Super Admins receive admin emails)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS NotificationRecipients (
    EventKey VARCHAR(60) NOT NULL,
    UserId   INT         NOT NULL,
    PRIMARY KEY (EventKey, UserId),
    KEY idx_notificationrecipients_user (UserId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 3. NotificationDigest
-- Admin emails for events set to "One summary email a day". The nightly
-- ranking-calculator job sends one summary per person per event, then
-- clears the rows it included.
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS NotificationDigest (
    Id          INT          NOT NULL AUTO_INCREMENT,
    EventKey    VARCHAR(60)  NOT NULL,
    UserId      INT          NOT NULL,
    Subject     VARCHAR(255) NOT NULL DEFAULT '',
    BodyText    TEXT         NULL,
    CreatedDate DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (Id),
    KEY idx_notificationdigest_user (UserId, EventKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 4. EmailTemplates
-- TemplateKey = event key plus audience, e.g. registration_approved.person.
-- A row exists only when the wording has been changed.
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS EmailTemplates (
    TemplateKey     VARCHAR(80)  NOT NULL,
    Subject         VARCHAR(255) NOT NULL,
    BodyHtml        MEDIUMTEXT   NOT NULL,
    UpdatedByUserId INT          NULL DEFAULT NULL,
    UpdatedDate     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (TemplateKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 5. TemplateAttachments
-- Files live in storage/email-attachments/ (blocked from the web).
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS TemplateAttachments (
    Id           INT          NOT NULL AUTO_INCREMENT,
    TemplateKey  VARCHAR(80)  NOT NULL,
    OriginalName VARCHAR(255) NOT NULL,
    StoredName   VARCHAR(255) NOT NULL,
    MimeType     VARCHAR(120) NOT NULL,
    SizeBytes    INT          NOT NULL DEFAULT 0,
    UploadedDate DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (Id),
    KEY idx_templateattachments_key (TemplateKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 6. Attachments kept with waiting emails and with broadcasts
-- --------------------------------------------
ALTER TABLE EmailLog
    ADD COLUMN IF NOT EXISTS AttachmentsJson TEXT NULL DEFAULT NULL;

ALTER TABLE EmailBroadcasts
    ADD COLUMN IF NOT EXISTS AttachmentsJson TEXT NULL DEFAULT NULL;


-- --------------------------------------------
-- 7. Settings: WhatsApp group link used by {whatsapp_link}
-- Uses the Description column only when it exists.
-- --------------------------------------------
SET @kbf_has_description := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'Settings'
      AND COLUMN_NAME = 'Description'
);

SET @kbf_sql := IF(
    @kbf_has_description > 0,
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue, Description) VALUES
        (''whatsapp_link'', ''https://chat.whatsapp.com/ItJTbUxRi9R56LFmkT99dp?s=sh&p=a&ilr=1'', ''KBF-UK WhatsApp group link used in emails'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''whatsapp_link'', ''https://chat.whatsapp.com/ItJTbUxRi9R56LFmkT99dp?s=sh&p=a&ilr=1'')'
);

PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
