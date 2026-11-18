-- ============================================
-- KBF-UK: GRADING PAGE AND BROADCASTS PAGE
-- Run in phpMyAdmin (MariaDB) AFTER database/notifications.sql.
-- Safe to run more than once. Never drops or deletes anything:
--   * ADD COLUMN IF NOT EXISTS / INSERT IGNORE
--   * column types are read from INFORMATION_SCHEMA before any change
--   * settings already saved are kept
--
-- 1. Settings: close_to_promotion_points (150)
-- 2. EmailBroadcasts.TargetType   'all', 'grades' or 'staff' (VARCHAR(20) NULL)
--      - added when missing
--      - when it is an ENUM it becomes VARCHAR(20) NULL; every existing value
--        is kept as it is ('all' and 'category' from the old page)
--      - an empty TargetType is read as the old target by the code
-- 3. EmailBroadcasts.TargetGrades  comma-separated grade ids
--    EmailBroadcasts.ScheduledFor  when a scheduled broadcast starts
--    EmailBroadcasts.UpdatedDate   last change to a draft or schedule
--    EmailBroadcasts.CancelledDate when it was cancelled
-- 4. EmailBroadcasts.Status accepts draft, scheduled, sending, paused,
--    completed and cancelled (only when the column is an ENUM; existing
--    values such as 'queued' are kept, and so are NULL and the default)
-- ============================================


-- --------------------------------------------
-- 1. Settings: how far below the threshold "Close to promotion" reaches
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
        (''close_to_promotion_points'', ''150'', ''Players within this many points of their grade threshold show under Close to promotion'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''close_to_promotion_points'', ''150'')'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 2. TargetType
-- Added when missing. When it is an ENUM it becomes VARCHAR(20) NULL, so
-- 'grades' and 'staff' fit; every stored value stays the same.
-- --------------------------------------------
ALTER TABLE EmailBroadcasts
    ADD COLUMN IF NOT EXISTS TargetType VARCHAR(20) NULL DEFAULT NULL;

SET @kbf_target_enum := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'EmailBroadcasts'
      AND COLUMN_NAME = 'TargetType'
      AND DATA_TYPE = 'enum'
);
SET @kbf_sql := IF(
    @kbf_target_enum = 0,
    'SELECT ''EmailBroadcasts.TargetType is already text'' AS info',
    'ALTER TABLE EmailBroadcasts MODIFY COLUMN TargetType VARCHAR(20) NULL DEFAULT NULL'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 3. Chosen grades, schedule and dates
-- --------------------------------------------
ALTER TABLE EmailBroadcasts
    ADD COLUMN IF NOT EXISTS TargetGrades VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ScheduledFor DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS UpdatedDate DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS CancelledDate DATETIME NULL DEFAULT NULL;


-- --------------------------------------------
-- 4. Status values
-- Only when Status is an ENUM. Each missing value is added at the end; the
-- column keeps its other values, whether it allows NULL, and its default.
-- --------------------------------------------
SET @kbf_status_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'EmailBroadcasts'
      AND COLUMN_NAME = 'Status'
      AND DATA_TYPE = 'enum'
);
SET @kbf_status_null := (
    SELECT IS_NULLABLE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailBroadcasts' AND COLUMN_NAME = 'Status'
);
SET @kbf_status_default := (
    SELECT COLUMN_DEFAULT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailBroadcasts' AND COLUMN_NAME = 'Status'
);

SET @kbf_new_type := @kbf_status_type;
SET @kbf_new_type := IF(@kbf_new_type IS NULL OR @kbf_new_type LIKE '%''draft''%', @kbf_new_type,
    CONCAT(LEFT(@kbf_new_type, CHAR_LENGTH(@kbf_new_type) - 1), ',''draft'')'));
SET @kbf_new_type := IF(@kbf_new_type IS NULL OR @kbf_new_type LIKE '%''scheduled''%', @kbf_new_type,
    CONCAT(LEFT(@kbf_new_type, CHAR_LENGTH(@kbf_new_type) - 1), ',''scheduled'')'));
SET @kbf_new_type := IF(@kbf_new_type IS NULL OR @kbf_new_type LIKE '%''sending''%', @kbf_new_type,
    CONCAT(LEFT(@kbf_new_type, CHAR_LENGTH(@kbf_new_type) - 1), ',''sending'')'));
SET @kbf_new_type := IF(@kbf_new_type IS NULL OR @kbf_new_type LIKE '%''paused''%', @kbf_new_type,
    CONCAT(LEFT(@kbf_new_type, CHAR_LENGTH(@kbf_new_type) - 1), ',''paused'')'));
SET @kbf_new_type := IF(@kbf_new_type IS NULL OR @kbf_new_type LIKE '%''completed''%', @kbf_new_type,
    CONCAT(LEFT(@kbf_new_type, CHAR_LENGTH(@kbf_new_type) - 1), ',''completed'')'));
SET @kbf_new_type := IF(@kbf_new_type IS NULL OR @kbf_new_type LIKE '%''cancelled''%', @kbf_new_type,
    CONCAT(LEFT(@kbf_new_type, CHAR_LENGTH(@kbf_new_type) - 1), ',''cancelled'')'));

SET @kbf_sql := IF(
    @kbf_status_type IS NULL OR @kbf_new_type = @kbf_status_type,
    'SELECT ''EmailBroadcasts.Status needs no change'' AS info',
    CONCAT(
        'ALTER TABLE EmailBroadcasts MODIFY COLUMN Status ', @kbf_new_type,
        IF(@kbf_status_null = 'YES', ' NULL', ' NOT NULL'),
        IF(@kbf_status_default IS NULL OR @kbf_status_default = 'NULL', '',
           CONCAT(' DEFAULT ',
                  IF(LEFT(@kbf_status_default, 1) = '''', @kbf_status_default,
                     CONCAT('''', @kbf_status_default, ''''))))
    )
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
