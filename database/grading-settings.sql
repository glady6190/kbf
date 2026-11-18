-- ============================================
-- KBF-UK: CONTROL CENTRE STAGE 3 - GRADES, POINTS AND SEASONS
-- Run in phpMyAdmin (MariaDB). Safe to run more than once.
-- Never drops or deletes anything:
--   * ADD COLUMN IF NOT EXISTS / INSERT IGNORE only
--   * Existing settings keep their current values
--
-- 1. Categories.IsActive: a retired grade (0) disappears from public tabs,
--    registration, broadcast targets and grade dropdowns, but stays in the
--    database so old history keeps working.
-- 2. Season settings. Without last_term_reset the season reset job could
--    never run, so the row is created (empty: the job's first run sets the
--    baseline and resets nobody).
-- ============================================


-- --------------------------------------------
-- 1. Categories: active flag (every existing grade stays active)
-- --------------------------------------------
ALTER TABLE Categories
    ADD COLUMN IF NOT EXISTS IsActive TINYINT(1) NOT NULL DEFAULT 1;


-- --------------------------------------------
-- 2. Season settings
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
        (''term_start_months'', ''1,7'', ''Months in which a new season starts, e.g. 1,7''),
        (''term_reset_floor_trigger'', ''250'', ''Players on this many season points or more restart on the floor value''),
        (''term_reset_floor_value'', ''150'', ''Season points those players restart on''),
        (''last_term_reset'', '''', ''The season the reset job last processed (set automatically)'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''term_start_months'', ''1,7''),
        (''term_reset_floor_trigger'', ''250''),
        (''term_reset_floor_value'', ''150''),
        (''last_term_reset'', '''')'
);

PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
