-- ============================================
-- KBF-UK: TERMS AND CONDITIONS - RECORD WHAT EACH PLAYER AGREED TO
-- Run in phpMyAdmin (MariaDB). Safe to run more than once.
-- Never drops or deletes anything:
--   * ADD COLUMN IF NOT EXISTS / INSERT IGNORE only
--   * An existing terms_version setting keeps its current value
--
-- 1. PlayerRegistrations and Users: TermsVersion and TermsAcceptedDate.
--    New registrations save the version from the terms_version setting and
--    the date and time; approval copies both to the new Users row.
--    Existing members keep NULL, meaning they agreed to an earlier version.
-- 2. terms_version setting ('2026-11'). Change it whenever the terms change.
-- ============================================


-- --------------------------------------------
-- 1. Columns
-- --------------------------------------------
ALTER TABLE PlayerRegistrations
    ADD COLUMN IF NOT EXISTS TermsVersion VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS TermsAcceptedDate DATETIME NULL;

ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS TermsVersion VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS TermsAcceptedDate DATETIME NULL;


-- --------------------------------------------
-- 2. Current terms version
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
        (''terms_version'', ''2026-11'', ''Version of the terms and conditions new players agree to'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''terms_version'', ''2026-11'')'
);

PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
