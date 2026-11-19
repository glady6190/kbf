-- ============================================
-- KBF-UK: TOURNAMENTS PAGE - RESULTS REMINDERS
-- Run in phpMyAdmin (MariaDB) AFTER database/notifications.sql.
-- Safe to run more than once. Never drops or deletes anything:
--   * ADD COLUMN IF NOT EXISTS / INSERT IGNORE
--   * switches and settings already saved are kept
--
-- 1. Tournaments.LastResultsReminderDate  last reminder an admin sent
--    Tournaments.AutoResultsReminderDate  the one automatic reminder
-- 2. Tournaments.Status accepts 'Cancelled' (only when the column is an
--    ENUM that does not list it yet; existing values are kept)
-- 3. NotificationEvents: results_overdue (organiser email, starts OFF)
-- 4. Settings: results_reminder_days (7)
--
-- The event key MUST match api/email-templates.php.
-- ============================================


-- --------------------------------------------
-- 1. Reminder dates
-- --------------------------------------------
ALTER TABLE Tournaments
    ADD COLUMN IF NOT EXISTS LastResultsReminderDate DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS AutoResultsReminderDate DATETIME NULL DEFAULT NULL;


-- --------------------------------------------
-- 2. 'Cancelled' status
-- Only when Status is an ENUM without 'Cancelled'. The column keeps its
-- other values, whether it allows NULL, its default, its character set and
-- collation, and its comment. (On the live database Status is VARCHAR(50),
-- so nothing changes there.) The table is found whatever the case of its
-- stored name.
-- --------------------------------------------
SET @kbf_tbl := (
    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'tournaments'
    LIMIT 1
);
SET @kbf_status_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = @kbf_tbl
      AND COLUMN_NAME = 'Status'
      AND DATA_TYPE = 'enum'
      AND COLUMN_TYPE NOT LIKE '%''Cancelled''%'
);
SET @kbf_status_null := (
    SELECT IS_NULLABLE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @kbf_tbl AND COLUMN_NAME = 'Status'
);
SET @kbf_status_default := (
    SELECT COLUMN_DEFAULT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @kbf_tbl AND COLUMN_NAME = 'Status'
);
SET @kbf_status_charset := (
    SELECT CONCAT(' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @kbf_tbl AND COLUMN_NAME = 'Status'
      AND CHARACTER_SET_NAME IS NOT NULL AND COLLATION_NAME IS NOT NULL
);
SET @kbf_status_comment := (
    SELECT COLUMN_COMMENT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @kbf_tbl AND COLUMN_NAME = 'Status'
);

SET @kbf_sql := IF(
    @kbf_status_type IS NULL,
    'SELECT ''Tournaments.Status already accepts Cancelled'' AS info',
    CONCAT(
        'ALTER TABLE `', @kbf_tbl, '` MODIFY COLUMN Status ',
        LEFT(@kbf_status_type, CHAR_LENGTH(@kbf_status_type) - 1), ',''Cancelled'')',
        COALESCE(@kbf_status_charset, ''),
        IF(@kbf_status_null = 'YES', ' NULL', ' NOT NULL'),
        IF(@kbf_status_default IS NULL OR @kbf_status_default = 'NULL', '',
           CONCAT(' DEFAULT ',
                  IF(LEFT(@kbf_status_default, 1) = '''', @kbf_status_default,
                     CONCAT('''', @kbf_status_default, '''')))),
        IF(@kbf_status_comment IS NULL OR @kbf_status_comment = '', '', CONCAT(' COMMENT ', QUOTE(@kbf_status_comment)))
    )
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 3. Notification event: results not yet submitted
-- Organiser email applies and starts OFF. An admin can still send it by
-- hand from the Tournaments page.
-- --------------------------------------------
INSERT IGNORE INTO NotificationEvents
    (EventKey, GroupName, Label, SortOrder, Priority,
     EmailPersonApplies, EmailPersonEnabled, EmailOrganiserApplies, EmailOrganiserEnabled,
     EmailAdminsApplies, EmailAdminsEnabled, NewsPostApplies, NewsPostEnabled,
     AdminPermission, AdminDelivery, Locked)
VALUES
    ('results_overdue', 'Tournaments and results', 'Results not yet submitted', 170, 'normal', 0, 0, 1, 0, 0, 0, 0, 0, NULL, 'immediate', 0);


-- --------------------------------------------
-- 4. Settings: days after the tournament for the automatic reminder
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
        (''results_reminder_days'', ''7'', ''Days after a tournament before the one automatic results reminder is sent'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''results_reminder_days'', ''7'')'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
