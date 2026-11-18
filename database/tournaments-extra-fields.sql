-- ============================================
-- KBF-UK: Tournaments - poster, registration link and extra details
-- Run once in phpMyAdmin (MariaDB). Safe to run again:
-- ADD COLUMN IF NOT EXISTS skips any column that is already there.
-- Every new column is nullable, so existing tournaments are unaffected.
-- ============================================

ALTER TABLE Tournaments
    ADD COLUMN IF NOT EXISTS PosterPath            VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS RegistrationUrl       VARCHAR(500) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS RegistrationCloseDate DATE         NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS EntryFee              VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS StartTime             TIME         NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS Description           TEXT         NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ContactName           VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ContactPhone          VARCHAR(40)  NULL DEFAULT NULL;
