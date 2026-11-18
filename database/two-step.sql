-- ============================================
-- KBF-UK: STAGE 5 - 2-STEP VERIFICATION (authenticator app, TOTP)
-- Safe to run more than once. Never drops or deletes anything.
--
-- 1. Users: TwoFactorSecret (AES-256-GCM encrypted, never plain text),
--    TwoFactorEnabledDate, TwoFactorRequired, TwoFactorLastStep
--    (the last accepted 30-second step, so a code cannot be reused).
-- 2. TwoFactorBackupCodes: one row per backup code, stored only as a hash.
-- 3. Settings: two_factor_policy = 'off' (off | optional |
--    required_admins | required_all). Existing value is kept.
--
-- Assumes Users.UserId is INT (as in permissions-foundation.sql).
-- ============================================

-- 1. Users columns
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS TwoFactorSecret      VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS TwoFactorEnabledDate DATETIME     NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS TwoFactorRequired    TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS TwoFactorLastStep    BIGINT       NULL DEFAULT NULL;

-- 2. Backup codes
CREATE TABLE IF NOT EXISTS TwoFactorBackupCodes (
    Id          INT          NOT NULL AUTO_INCREMENT,
    UserId      INT          NOT NULL,
    CodeHash    VARCHAR(255) NOT NULL,
    UsedDate    DATETIME     NULL DEFAULT NULL,
    CreatedDate DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (Id),
    KEY idx_twofactorbackupcodes_user (UserId),
    CONSTRAINT fk_twofactorbackupcodes_user
        FOREIGN KEY (UserId) REFERENCES Users (UserId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Policy setting. The Settings table's description column name is not
--    fixed across installs, so it is used only when a column named
--    Description exists; otherwise the row is added without it.
SET @kbf_has_description := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'Settings'
      AND COLUMN_NAME = 'Description'
);

SET @kbf_sql := IF(
    @kbf_has_description > 0,
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue, Description) VALUES (''two_factor_policy'', ''off'', ''2-step verification policy: off, optional, required_admins or required_all'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES (''two_factor_policy'', ''off'')'
);

PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
