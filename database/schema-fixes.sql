-- ============================================
-- KBF-UK: SCHEMA FIXES (audit batch 3: DB-08, DB-09, DB-10, DB-14, DB-17,
-- PERF-04)
-- Run in phpMyAdmin (MariaDB) LAST, after every other script in
-- database/RUN-ORDER.md (it needs the columns and tables they add).
--
-- Safe to run more than once. Never drops a table or deletes a row:
--   * every change first checks INFORMATION_SCHEMA and is skipped when it
--     is already done
--   * the only indexes dropped are exact duplicates: another index with
--     exactly the same columns stays on the same table, so any foreign key
--     on those columns keeps an index
--
-- Starting structure: database/base-schema-live.sql (MariaDB 11.8, every
-- table InnoDB).
--
-- 1. Collations: the eight utf8mb4_uca1400_ai_ci tables become
--    utf8mb4_unicode_ci like every other table (no "Illegal mix of
--    collations" when text from two tables is compared or combined)
-- 2. Settings.SettingValue: TEXT NOT NULL (was VARCHAR(255); longer values
--    were cut off). Every value is kept.
-- 3. Columns the code needs that the live structure does not allow:
--      Users.PostCode NULL       (players and staff without a post code,
--                                 "remove at their request")
--      Users.CategoryId NULL     (staff who do not play have no grade)
--      Categories.Name 60 chars  (the Grading page allows 60)
-- 4. Old broadcasts: 'queued' (old page) and any unknown status settled;
--    the column default becomes 'draft'
-- 5. Indexes the current queries need (only when missing)
-- 6. Exact duplicate indexes removed
-- 7. Email sending (audit batch 4): EmailLog.NextAttemptDate (an email
--    with repeated temporary failures is skipped until then) and the
--    email_reserve_normal setting (10)
-- 8. Notification event registration_duplicate (audit batch 5)
-- ============================================


-- --------------------------------------------
-- 1. COLLATIONS
-- A table is converted when its default or any of its text columns is not
-- utf8mb4_unicode_ci. CONVERT TO changes every text column and keeps the
-- data. Before running: see RUN-ORDER.md (backup; duplicate check on the
-- unique text keys).
-- --------------------------------------------

-- ApprovalLogs
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ApprovalLogs' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci')
  + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ApprovalLogs' AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE ApprovalLogs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT ''ApprovalLogs already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- EmailBroadcasts
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailBroadcasts' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci')
  + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailBroadcasts' AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE EmailBroadcasts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT ''EmailBroadcasts already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- EmailQueue
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailQueue' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci')
  + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailQueue' AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE EmailQueue CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT ''EmailQueue already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- LoginAttempts
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'LoginAttempts' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci')
  + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'LoginAttempts' AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE LoginAttempts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT ''LoginAttempts already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PasswordResets
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PasswordResets' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci')
  + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PasswordResets' AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE PasswordResets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT ''PasswordResets already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerRegistrations
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerRegistrations' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci')
  + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerRegistrations' AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE PlayerRegistrations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT ''PlayerRegistrations already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- RoundPoints
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'RoundPoints' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci')
  + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'RoundPoints' AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE RoundPoints CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT ''RoundPoints already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Settings
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Settings' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci')
  + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Settings' AND COLLATION_NAME <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE Settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'SELECT ''Settings already utf8mb4_unicode_ci'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 2. Settings.SettingValue: TEXT NOT NULL (every value kept)
-- --------------------------------------------
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Settings' AND COLUMN_NAME = 'SettingValue'
       AND DATA_TYPE = 'text' AND IS_NULLABLE = 'NO') > 0,
    'SELECT ''Settings.SettingValue is already TEXT NOT NULL'' AS info',
    'ALTER TABLE Settings MODIFY COLUMN SettingValue TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 3. Columns the code needs
-- --------------------------------------------

-- Users.PostCode allows NULL (live: varchar(20) NOT NULL). The site saves
-- NULL for a player or staff member with no post code and when a person is
-- removed at their request.
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users' AND COLUMN_NAME = 'PostCode' AND IS_NULLABLE = 'NO') = 0,
    'SELECT ''Users.PostCode already allows NULL'' AS info',
    'ALTER TABLE Users MODIFY COLUMN PostCode VARCHAR(20) NULL DEFAULT NULL'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Users.CategoryId allows NULL (live: int(11) NOT NULL DEFAULT 4). Staff who
-- do not play are created with no grade; the default of 4 is kept and the
-- foreign key to Categories is not touched.
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users' AND COLUMN_NAME = 'CategoryId' AND IS_NULLABLE = 'NO') = 0,
    'SELECT ''Users.CategoryId already allows NULL'' AS info',
    'ALTER TABLE Users MODIFY COLUMN CategoryId INT(11) NULL DEFAULT 4'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Categories.Name up to 60 characters (live: varchar(30); the Grading page
-- accepts 60, so longer names were cut off)
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Categories' AND COLUMN_NAME = 'Name'
       AND CHARACTER_MAXIMUM_LENGTH < 60) = 0,
    'SELECT ''Categories.Name already holds 60 characters'' AS info',
    'ALTER TABLE Categories MODIFY COLUMN Name VARCHAR(60) NULL DEFAULT NULL'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 4. OLD BROADCASTS (DB-17)
-- The current pages never write 'queued' (they use 'sending'); only the old
-- page did, and the column default was 'queued'. An old broadcast is
-- settled so the sender never starts or finishes it on its own:
--   * no recipients still pending  -> 'completed'
--   * recipients still pending     -> 'paused' (an admin can resume or
--     cancel it on the Broadcasts page)
-- The same applies to any status the current code does not know.
-- Statuses are first stored in lower case without spaces.
-- --------------------------------------------
UPDATE EmailBroadcasts
SET Status = LOWER(TRIM(Status))
WHERE BINARY Status <> BINARY LOWER(TRIM(Status));

UPDATE EmailBroadcasts b
SET b.Status = 'completed',
    b.CompletedDate = COALESCE(b.CompletedDate, b.CreatedDate)
WHERE (b.Status = 'queued'
       OR b.Status NOT IN ('draft', 'scheduled', 'queued', 'sending', 'paused', 'completed', 'cancelled'))
  AND NOT EXISTS (SELECT 1 FROM EmailQueue q WHERE q.BroadcastId = b.BroadcastId AND q.Status = 'pending');

UPDATE EmailBroadcasts b
SET b.Status = 'paused'
WHERE (b.Status = 'queued'
       OR b.Status NOT IN ('draft', 'scheduled', 'queued', 'sending', 'paused', 'completed', 'cancelled'))
  AND EXISTS (SELECT 1 FROM EmailQueue q WHERE q.BroadcastId = b.BroadcastId AND q.Status = 'pending');

ALTER TABLE EmailBroadcasts ALTER COLUMN Status SET DEFAULT 'draft';


-- --------------------------------------------
-- 5. INDEXES (only when missing)
-- An index counts as present when an existing index on the table has
-- exactly these columns, or starts with them in this order.
-- --------------------------------------------

-- PlayerRankingHistory (PlayerId, ChangeDate): latest grade change per player
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerRankingHistory'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'PlayerId,ChangeDate' OR s.cols LIKE 'PlayerId,ChangeDate,%') > 0,
    'SELECT ''PlayerRankingHistory (PlayerId, ChangeDate) already indexed'' AS info',
    'CREATE INDEX idx_prh_player_date ON PlayerRankingHistory (PlayerId, ChangeDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerPointsHistory (PlayerId, AwardedDate): career, six-month and grade points
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerPointsHistory'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'PlayerId,AwardedDate' OR s.cols LIKE 'PlayerId,AwardedDate,%') > 0,
    'SELECT ''PlayerPointsHistory (PlayerId, AwardedDate) already indexed'' AS info',
    'CREATE INDEX idx_pph_player_awarded ON PlayerPointsHistory (PlayerId, AwardedDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerPointsHistory (PlayerId, CountsTowardSeason): marking awards as no
-- longer counted (grade change, season reset)
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerPointsHistory'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'PlayerId,CountsTowardSeason' OR s.cols LIKE 'PlayerId,CountsTowardSeason,%') > 0,
    'SELECT ''PlayerPointsHistory (PlayerId, CountsTowardSeason) already indexed'' AS info',
    'CREATE INDEX idx_pph_player_season ON PlayerPointsHistory (PlayerId, CountsTowardSeason)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerPointsHistory (MatchId, PlayerId): reversing and relinking awards,
-- the profile join and the career-only final check
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerPointsHistory'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'MatchId,PlayerId' OR s.cols LIKE 'MatchId,PlayerId,%') > 0,
    'SELECT ''PlayerPointsHistory (MatchId, PlayerId) already indexed'' AS info',
    'CREATE INDEX idx_pph_match_player ON PlayerPointsHistory (MatchId, PlayerId)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Tournaments (Status, TournamentDate): public lists, reminders, overview
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Tournaments'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Status,TournamentDate' OR s.cols LIKE 'Status,TournamentDate,%') > 0,
    'SELECT ''Tournaments (Status, TournamentDate) already indexed'' AS info',
    'CREATE INDEX idx_tournaments_status_date ON Tournaments (Status, TournamentDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- AuditLog (TargetType, TargetId, CreatedDate): person and tournament history
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'AuditLog'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'TargetType,TargetId,CreatedDate' OR s.cols LIKE 'TargetType,TargetId,CreatedDate,%') > 0,
    'SELECT ''AuditLog (TargetType, TargetId, CreatedDate) already indexed'' AS info',
    'CREATE INDEX idx_auditlog_target ON AuditLog (TargetType, TargetId, CreatedDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- AuditLog (ActorUserId, CreatedDate): activity of one person, newest first
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'AuditLog'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'ActorUserId,CreatedDate' OR s.cols LIKE 'ActorUserId,CreatedDate,%') > 0,
    'SELECT ''AuditLog (ActorUserId, CreatedDate) already indexed'' AS info',
    'CREATE INDEX idx_auditlog_actor_date ON AuditLog (ActorUserId, CreatedDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- EmailLog (Status, Priority, CreatedDate): the waiting list the sender works through
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailLog'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Status,Priority,CreatedDate' OR s.cols LIKE 'Status,Priority,CreatedDate,%') > 0,
    'SELECT ''EmailLog (Status, Priority, CreatedDate) already indexed'' AS info',
    'CREATE INDEX idx_emaillog_status_priority ON EmailLog (Status, Priority, CreatedDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- EmailLog (Status, SentDate): emails sent in the last 24 hours (daily limit)
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailLog'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Status,SentDate' OR s.cols LIKE 'Status,SentDate,%') > 0,
    'SELECT ''EmailLog (Status, SentDate) already indexed'' AS info',
    'CREATE INDEX idx_emaillog_status_sent ON EmailLog (Status, SentDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- EmailLog (ToEmail): removing a person at their request
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailLog'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'ToEmail' OR s.cols LIKE 'ToEmail,%') > 0,
    'SELECT ''EmailLog (ToEmail) already indexed'' AS info',
    'CREATE INDEX idx_emaillog_toemail ON EmailLog (ToEmail)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Matches (TournamentId, IsApproved): results, counts and approvals
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'TournamentId,IsApproved' OR s.cols LIKE 'TournamentId,IsApproved,%') > 0,
    'SELECT ''Matches (TournamentId, IsApproved) already indexed'' AS info',
    'CREATE INDEX idx_matches_tournament_approved ON Matches (TournamentId, IsApproved)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Users (IsActive, IsRemoved, CategoryId): rankings, nightly job, broadcast recipients
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'IsActive,IsRemoved,CategoryId' OR s.cols LIKE 'IsActive,IsRemoved,CategoryId,%') > 0,
    'SELECT ''Users (IsActive, IsRemoved, CategoryId) already indexed'' AS info',
    'CREATE INDEX idx_users_active_removed_grade ON Users (IsActive, IsRemoved, CategoryId)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Users (UnsubscribeToken): the unsubscribe page
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'UnsubscribeToken' OR s.cols LIKE 'UnsubscribeToken,%') > 0,
    'SELECT ''Users (UnsubscribeToken) already indexed'' AS info',
    'CREATE INDEX idx_users_unsubscribe ON Users (UnsubscribeToken)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Users (ProfilePhoto, first 191 characters): which post images are a
-- player's photo (under-18 check on public post images)
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'ProfilePhoto' OR s.cols LIKE 'ProfilePhoto,%') > 0,
    'SELECT ''Users (ProfilePhoto) already indexed'' AS info',
    'CREATE INDEX idx_users_profilephoto ON Users (ProfilePhoto(191))'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- GalleryPhotos (TournamentId, DisplayOrder): gallery pages
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'GalleryPhotos'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'TournamentId,DisplayOrder' OR s.cols LIKE 'TournamentId,DisplayOrder,%') > 0,
    'SELECT ''GalleryPhotos (TournamentId, DisplayOrder) already indexed'' AS info',
    'CREATE INDEX idx_gallery_tournament_order ON GalleryPhotos (TournamentId, DisplayOrder)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- EmailQueue (UserId): removing a person at their request
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailQueue'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'UserId' OR s.cols LIKE 'UserId,%') > 0,
    'SELECT ''EmailQueue (UserId) already indexed'' AS info',
    'CREATE INDEX idx_emailqueue_user ON EmailQueue (UserId)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- EmailQueue (Email): removing a person at their request
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailQueue'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Email' OR s.cols LIKE 'Email,%') > 0,
    'SELECT ''EmailQueue (Email) already indexed'' AS info',
    'CREATE INDEX idx_emailqueue_email ON EmailQueue (Email)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- EmailBroadcasts (Status, ScheduledFor): the sender checks it every minute
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'EmailBroadcasts'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Status,ScheduledFor' OR s.cols LIKE 'Status,ScheduledFor,%') > 0,
    'SELECT ''EmailBroadcasts (Status, ScheduledFor) already indexed'' AS info',
    'CREATE INDEX idx_broadcasts_status_scheduled ON EmailBroadcasts (Status, ScheduledFor)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- LoginAttempts (IpAddress, AttemptTime): failed sign-ins per address
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'LoginAttempts'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'IpAddress,AttemptTime' OR s.cols LIKE 'IpAddress,AttemptTime,%') > 0,
    'SELECT ''LoginAttempts (IpAddress, AttemptTime) already indexed'' AS info',
    'CREATE INDEX idx_loginattempts_ip_time ON LoginAttempts (IpAddress, AttemptTime)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- ApprovalLogs (LogType, ReferenceId): approval history
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ApprovalLogs'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'LogType,ReferenceId' OR s.cols LIKE 'LogType,ReferenceId,%') > 0,
    'SELECT ''ApprovalLogs (LogType, ReferenceId) already indexed'' AS info',
    'CREATE INDEX idx_approvallogs_type_ref ON ApprovalLogs (LogType, ReferenceId)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- UserActivityLogs (TargetUserId, LogDate): person history
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'UserActivityLogs'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'TargetUserId,LogDate' OR s.cols LIKE 'TargetUserId,LogDate,%') > 0,
    'SELECT ''UserActivityLogs (TargetUserId, LogDate) already indexed'' AS info',
    'CREATE INDEX idx_activitylogs_target_date ON UserActivityLogs (TargetUserId, LogDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerRegistrations (Status, SubmittedDate): approvals queue and counts
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerRegistrations'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Status,SubmittedDate' OR s.cols LIKE 'Status,SubmittedDate,%') > 0,
    'SELECT ''PlayerRegistrations (Status, SubmittedDate) already indexed'' AS info',
    'CREATE INDEX idx_registrations_status_date ON PlayerRegistrations (Status, SubmittedDate)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerRegistrations (Email): duplicate checks on the registration form
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM (
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerRegistrations'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Email' OR s.cols LIKE 'Email,%') > 0,
    'SELECT ''PlayerRegistrations (Email) already indexed'' AS info',
    'CREATE INDEX idx_registrations_email ON PlayerRegistrations (Email)'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 6. EXACT DUPLICATE INDEXES
-- Each index below is dropped only when it exists, has exactly the columns
-- shown, and ANOTHER index on the same table has exactly the same columns.
-- That other index stays, so any foreign key on these columns still has
-- its index. Kept indexes are named in each comment.
-- --------------------------------------------

-- Clubs.idx_isactive (IsActive); kept: idx_active
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Clubs' AND INDEX_NAME = 'idx_isactive') = 'IsActive'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Clubs'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'IsActive' AND s.INDEX_NAME <> 'idx_isactive') > 0,
    'DROP INDEX idx_isactive ON Clubs',
    'SELECT ''Clubs.idx_isactive: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Matches.idx_tournament (TournamentId); kept: TournamentId (foreign key Matches_ibfk_1)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches' AND INDEX_NAME = 'idx_tournament') = 'TournamentId'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'TournamentId' AND s.INDEX_NAME <> 'idx_tournament') > 0,
    'DROP INDEX idx_tournament ON Matches',
    'SELECT ''Matches.idx_tournament: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Matches.idx_player1 (Player1Id); kept: Player1Id (foreign key Matches_ibfk_3)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches' AND INDEX_NAME = 'idx_player1') = 'Player1Id'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Player1Id' AND s.INDEX_NAME <> 'idx_player1') > 0,
    'DROP INDEX idx_player1 ON Matches',
    'SELECT ''Matches.idx_player1: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Matches.idx_player2 (Player2Id); kept: Player2Id (foreign key Matches_ibfk_5)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches' AND INDEX_NAME = 'idx_player2') = 'Player2Id'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Player2Id' AND s.INDEX_NAME <> 'idx_player2') > 0,
    'DROP INDEX idx_player2 ON Matches',
    'SELECT ''Matches.idx_player2: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Matches.idx_category (CategoryId); kept: CategoryId (foreign key Matches_ibfk_2)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches' AND INDEX_NAME = 'idx_category') = 'CategoryId'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Matches'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'CategoryId' AND s.INDEX_NAME <> 'idx_category') > 0,
    'DROP INDEX idx_category ON Matches',
    'SELECT ''Matches.idx_category: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PasswordResets.idx_token (Token); kept: Token (unique)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PasswordResets' AND INDEX_NAME = 'idx_token') = 'Token'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PasswordResets'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'Token' AND s.INDEX_NAME <> 'idx_token') > 0,
    'DROP INDEX idx_token ON PasswordResets',
    'SELECT ''PasswordResets.idx_token: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerPerformanceTracker.idx_player (PlayerId); kept: PlayerId (unique;
-- foreign key PlayerPerformanceTracker_ibfk_1)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerPerformanceTracker' AND INDEX_NAME = 'idx_player') = 'PlayerId'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerPerformanceTracker'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'PlayerId' AND s.INDEX_NAME <> 'idx_player') > 0,
    'DROP INDEX idx_player ON PlayerPerformanceTracker',
    'SELECT ''PlayerPerformanceTracker.idx_player: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- PlayerPerformanceTracker.idx_category (CategoryId); kept: CategoryId
-- (foreign key PlayerPerformanceTracker_ibfk_2)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerPerformanceTracker' AND INDEX_NAME = 'idx_category') = 'CategoryId'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'PlayerPerformanceTracker'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'CategoryId' AND s.INDEX_NAME <> 'idx_category') > 0,
    'DROP INDEX idx_category ON PlayerPerformanceTracker',
    'SELECT ''PlayerPerformanceTracker.idx_category: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- ResultsApprovalQueue.idx_tournament (TournamentId); kept: TournamentId
-- (foreign key ResultsApprovalQueue_ibfk_1)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ResultsApprovalQueue' AND INDEX_NAME = 'idx_tournament') = 'TournamentId'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ResultsApprovalQueue'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'TournamentId' AND s.INDEX_NAME <> 'idx_tournament') > 0,
    'DROP INDEX idx_tournament ON ResultsApprovalQueue',
    'SELECT ''ResultsApprovalQueue.idx_tournament: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Tournaments.idx_organizing_club (OrganizingClubId); kept: OrganizingClubId
-- (foreign key Tournaments_ibfk_1)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Tournaments' AND INDEX_NAME = 'idx_organizing_club') = 'OrganizingClubId'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Tournaments'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'OrganizingClubId' AND s.INDEX_NAME <> 'idx_organizing_club') > 0,
    'DROP INDEX idx_organizing_club ON Tournaments',
    'SELECT ''Tournaments.idx_organizing_club: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Users.idx_player_id (PlayerId); kept: PlayerId (unique)
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users' AND INDEX_NAME = 'idx_player_id') = 'PlayerId'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'PlayerId' AND s.INDEX_NAME <> 'idx_player_id') > 0,
    'DROP INDEX idx_player_id ON Users',
    'SELECT ''Users.idx_player_id: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- Users.idx_isactive (IsActive); kept: idx_active
SET @kbf_sql := IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users' AND INDEX_NAME = 'idx_isactive') = 'IsActive'
    AND (SELECT COUNT(*) FROM (
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users'
        GROUP BY INDEX_NAME) s
     WHERE s.cols = 'IsActive' AND s.INDEX_NAME <> 'idx_isactive') > 0,
    'DROP INDEX idx_isactive ON Users',
    'SELECT ''Users.idx_isactive: nothing to drop'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 7. EMAIL SENDING
-- --------------------------------------------

-- EmailLog.NextAttemptDate: after repeated temporary failures an email is
-- skipped until this time, so it never holds up the others (empty = send
-- whenever the allowance allows)
ALTER TABLE EmailLog
    ADD COLUMN IF NOT EXISTS NextAttemptDate DATETIME NULL DEFAULT NULL;

-- The everyday-email reserve (Email and limits page): kept back from
-- broadcasts so everyday emails always have room
INSERT IGNORE INTO Settings (SettingKey, SettingValue, Description) VALUES
    ('email_reserve_normal', '10', 'Part of the daily limit kept back from broadcasts for everyday emails');


-- --------------------------------------------
-- 8. NOTIFICATION EVENT: registration_duplicate (audit batch 5, SEC-13)
-- The registration page gives the same reply whether or not the email or
-- person is already a member or waiting; the reason goes by email to the
-- address given. Person email, important, switched on. The event key MUST
-- match api/email-templates.php.
-- --------------------------------------------
INSERT IGNORE INTO NotificationEvents
    (EventKey, GroupName, Label, SortOrder, Priority,
     EmailPersonApplies, EmailPersonEnabled, EmailOrganiserApplies, EmailOrganiserEnabled,
     EmailAdminsApplies, EmailAdminsEnabled, NewsPostApplies, NewsPostEnabled,
     AdminPermission, AdminDelivery, Locked)
VALUES
    ('registration_duplicate', 'Players', 'Already registered or waiting', 15, 'important', 1, 1, 0, 0, 0, 0, 0, 0, NULL, 'immediate', 0);
