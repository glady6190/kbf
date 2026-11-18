-- ============================================
-- KBF-UK: CONTROL CENTRE STAGE 5 - REPORTS AND ACTIVITY LOG
-- Run in phpMyAdmin (MariaDB). Safe to run more than once.
-- Never drops or deletes anything.
--
-- 1. Settings: audit_log_retention_days (730 days); existing value kept
-- 2. Indexes for report speed, each created only when the column has no
--    index starting with it yet:
--      AuditLog.Action
--      PlayerPointsHistory.AwardedDate
--      PlayerRankingHistory.ChangeDate
--      PlayerRegistrations.SubmittedDate
--    (column names as used by the site's own queries)
-- ============================================


-- --------------------------------------------
-- 1. Settings
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
        (''audit_log_retention_days'', ''730'', ''Activity log entries older than this many days are deleted by the nightly job'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''audit_log_retention_days'', ''730'')'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 2. Indexes (only when missing)
-- An index counts as present when the column is the first column of any
-- existing index on the table.
-- --------------------------------------------

-- AuditLog.Action
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'AuditLog'
       AND COLUMN_NAME = 'Action' AND SEQ_IN_INDEX = 1) > 0,
    'SELECT ''AuditLog.Action already indexed''',
    'CREATE INDEX idx_auditlog_action ON AuditLog (Action)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerPointsHistory.AwardedDate
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerPointsHistory'
       AND COLUMN_NAME = 'AwardedDate' AND SEQ_IN_INDEX = 1) > 0,
    'SELECT ''PlayerPointsHistory.AwardedDate already indexed''',
    'CREATE INDEX idx_playerpointshistory_awarded ON PlayerPointsHistory (AwardedDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerRankingHistory.ChangeDate
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerRankingHistory'
       AND COLUMN_NAME = 'ChangeDate' AND SEQ_IN_INDEX = 1) > 0,
    'SELECT ''PlayerRankingHistory.ChangeDate already indexed''',
    'CREATE INDEX idx_playerrankinghistory_changedate ON PlayerRankingHistory (ChangeDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerRegistrations.SubmittedDate
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerRegistrations'
       AND COLUMN_NAME = 'SubmittedDate' AND SEQ_IN_INDEX = 1) > 0,
    'SELECT ''PlayerRegistrations.SubmittedDate already indexed''',
    'CREATE INDEX idx_playerregistrations_submitted ON PlayerRegistrations (SubmittedDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
