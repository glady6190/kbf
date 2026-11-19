-- ============================================
-- KBF-UK: ONE-TIME REQUEST IDS (no duplicate saves)
-- Safe to run more than once. Never drops or deletes anything.
--
-- Pages send a one-time id (header X-Request-Id) when they create a news
-- post, a tournament, a player or a set of results. api/request-ids.php
-- records each id here with the user who sent it; a repeat of the same id
-- (a double tap, or a retry after a lost connection) gets the first
-- answer back instead of creating a second record.
--
-- ResponseJson is NULL while the first request is still being saved.
-- Rows older than 7 days are removed by the site itself (now and then,
-- when a new id is stored).
--
-- Until this table exists the site skips the check (and logs once a day
-- that it is missing).
-- Assumes Users.UserId is INT (as in permissions-foundation.sql).
-- ============================================

CREATE TABLE IF NOT EXISTS RequestIds (
    UserId       INT          NOT NULL,
    RequestId    VARCHAR(64)  NOT NULL,
    Scope        VARCHAR(40)  NOT NULL,
    RecordId     VARCHAR(64)  NULL DEFAULT NULL,
    ResponseJson MEDIUMTEXT   NULL DEFAULT NULL,
    CreatedDate  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (UserId, RequestId),
    KEY idx_requestids_created (CreatedDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A table made by an earlier version of this script had RequestId in
-- ascii / ascii_bin: it now uses utf8mb4_unicode_ci like every other text
-- column (only when it still differs; the ids are kept)
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'RequestIds' AND COLUMN_NAME = 'RequestId'
       AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE RequestIds MODIFY COLUMN RequestId VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
    'SELECT ''RequestIds.RequestId already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
