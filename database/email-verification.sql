-- ============================================
-- KBF-UK: EMAIL CODE AT REGISTRATION, JOB CHECK-INS
-- Run in phpMyAdmin (MariaDB) AFTER database/notifications.sql.
-- Safe to run more than once. Never drops or deletes anything:
--   * CREATE TABLE IF NOT EXISTS / INSERT IGNORE
--   * switches, wording and settings already saved are kept
--
-- 1. EmailVerificationCodes  the 6-digit codes sent from the registration
--    form: the email, a hash of the code (never the code itself), expiry,
--    wrong attempts, created time and the requester's IP address. The
--    nightly job (jobs/ranking-calculator.php) deletes rows older than a day.
-- 2. NotificationEvents: registration_code (person email, important,
--    locked on; the wording can be changed on the Notifications page)
-- 3. Settings: job_checkin_broadcast_sender, job_checkin_ranking_calculator
--    and job_checkin_term_reset. Every run of each scheduled job writes its
--    time here (one UPDATE, no new rows); the Overview's "Last ran" and the
--    overdue warnings use it.
--
-- Until this script has run, the registration form works exactly as before
-- (no code step). The event key MUST match api/email-templates.php.
-- ============================================


-- --------------------------------------------
-- 1. Verification codes
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS EmailVerificationCodes (
    Id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    Email         VARCHAR(255)     NOT NULL,
    CodeHash      CHAR(64)         NOT NULL,
    ExpiresDate   DATETIME         NOT NULL,
    Attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    CreatedDate   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    IpAddress     VARCHAR(45)      NULL DEFAULT NULL,
    VerifiedDate  DATETIME         NULL DEFAULT NULL,
    UsedDate      DATETIME         NULL DEFAULT NULL,
    PRIMARY KEY (Id),
    KEY idx_emailverification_email (Email, CreatedDate),
    KEY idx_emailverification_ip (IpAddress, CreatedDate),
    KEY idx_emailverification_created (CreatedDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 2. Notification event: registration code
-- Person email, important, locked on.
-- --------------------------------------------
INSERT IGNORE INTO NotificationEvents
    (EventKey, GroupName, Label, SortOrder, Priority,
     EmailPersonApplies, EmailPersonEnabled, EmailOrganiserApplies, EmailOrganiserEnabled,
     EmailAdminsApplies, EmailAdminsEnabled, NewsPostApplies, NewsPostEnabled,
     AdminPermission, AdminDelivery, Locked)
VALUES
    ('registration_code', 'Players', 'Registration email code', 5, 'important', 1, 1, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 1);


-- --------------------------------------------
-- 3. Settings: last check-in of each scheduled job (empty until it runs)
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
        (''job_checkin_broadcast_sender'', '''', ''Last time the email sender ran (written by the job)''),
        (''job_checkin_ranking_calculator'', '''', ''Last time the nightly recalculation ran (written by the job)''),
        (''job_checkin_term_reset'', '''', ''Last time the season reset check ran (written by the job)'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''job_checkin_broadcast_sender'', ''''),
        (''job_checkin_ranking_calculator'', ''''),
        (''job_checkin_term_reset'', '''')'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- Checks (each should return one row with 1)
-- --------------------------------------------
SELECT COUNT(*) AS email_verification_table FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailVerificationCodes';
SELECT COUNT(*) AS registration_code_event FROM NotificationEvents WHERE EventKey = 'registration_code';
SELECT COUNT(*) = 3 AS job_checkin_settings FROM Settings
WHERE SettingKey IN ('job_checkin_broadcast_sender', 'job_checkin_ranking_calculator', 'job_checkin_term_reset');
