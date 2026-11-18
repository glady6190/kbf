-- ============================================
-- KBF-UK: CONTROL CENTRE STAGE 1 - EMAIL ENGINE
-- Run in phpMyAdmin (MariaDB). Safe to run more than once.
-- Never drops or deletes anything:
--   * CREATE TABLE IF NOT EXISTS / INSERT IGNORE only
--   * Existing settings keep their current values
--
-- 1. EmailLog: one row for every email the site sends (written before sending)
-- 2. JobRuns: one row per scheduled job run
-- 3. Settings: daily limit, reserve, pause, log retention, broadcast batch size
-- 4. Permissions: the "Control centre" group (granted to nobody; Super Admins
--    have every permission automatically)
--
-- The six control.* keys MUST match api/permissions.php.
-- ============================================


-- --------------------------------------------
-- 1. EmailLog
-- Priority: important | normal | bulk
-- Status:   waiting | sent | failed
-- Password reset links are never stored (replaced by "[reset link]").
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS EmailLog (
    Id          INT          NOT NULL AUTO_INCREMENT,
    EventKey    VARCHAR(60)  NOT NULL,
    Priority    ENUM('important', 'normal', 'bulk') NOT NULL DEFAULT 'normal',
    ToEmail     VARCHAR(255) NOT NULL,
    ToName      VARCHAR(255) NULL DEFAULT NULL,
    Subject     VARCHAR(255) NOT NULL DEFAULT '',
    BodyHtml    MEDIUMTEXT   NULL,
    BodyText    MEDIUMTEXT   NULL,
    Status      ENUM('waiting', 'sent', 'failed') NOT NULL DEFAULT 'waiting',
    Attempts    INT          NOT NULL DEFAULT 0,
    LastError   VARCHAR(500) NULL DEFAULT NULL,
    BroadcastId INT          NULL DEFAULT NULL,
    RelatedType VARCHAR(40)  NULL DEFAULT NULL,
    RelatedId   INT          NULL DEFAULT NULL,
    CreatedDate DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    SentDate    DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (Id),
    KEY idx_emaillog_status (Status),
    KEY idx_emaillog_sent (SentDate),
    KEY idx_emaillog_created (CreatedDate),
    KEY idx_emaillog_broadcast (BroadcastId, RelatedId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 2. JobRuns
-- Status: ok | error. Dry runs write nothing.
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS JobRuns (
    Id           INT          NOT NULL AUTO_INCREMENT,
    JobName      VARCHAR(60)  NOT NULL,
    StartedDate  DATETIME     NOT NULL,
    FinishedDate DATETIME     NULL DEFAULT NULL,
    Status       ENUM('ok', 'error') NOT NULL DEFAULT 'ok',
    Summary      VARCHAR(500) NULL DEFAULT NULL,
    PRIMARY KEY (Id),
    KEY idx_jobruns_job (JobName, StartedDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 3. Settings
-- The Settings table's description column is not fixed across installs, so
-- it is used only when a column named Description exists.
-- INSERT IGNORE keeps any value that is already there, so
-- broadcast_batch_size is set to 20 only when it does not exist yet.
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
        (''email_daily_limit'', ''100'', ''Most emails the site may send in any 24 hours''),
        (''email_reserve_important'', ''20'', ''Part of the daily limit kept back for approvals and password resets''),
        (''email_paused_until'', '''', ''Sending is paused until this time after the mail server refused for its own rate limit''),
        (''email_log_retention_days'', ''180'', ''Email log rows older than this many days are deleted''),
        (''broadcast_batch_size'', ''20'', ''Broadcast emails sent per run of the broadcast job'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''email_daily_limit'', ''100''),
        (''email_reserve_important'', ''20''),
        (''email_paused_until'', ''''),
        (''email_log_retention_days'', ''180''),
        (''broadcast_batch_size'', ''20'')'
);

PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 4. Permissions: Control centre group
-- Granted to nobody. Super Admins have every permission automatically.
-- --------------------------------------------
INSERT IGNORE INTO Permissions (PermissionKey, Label, GroupName, SortOrder) VALUES
    ('control.email',          'Email and limits',          'Control centre', 310),
    ('control.grading',        'Grading and seasons',       'Control centre', 320),
    ('control.notifications',  'Notifications and emails',  'Control centre', 330),
    ('control.reports',        'Reports and exports',       'Control centre', 340),
    ('control.site_settings',  'Site settings',             'Control centre', 350),
    ('control.activity_log',   'Activity log',              'Control centre', 360);
