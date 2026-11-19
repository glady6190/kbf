-- ============================================
-- KBF-UK: MIGRATION CHECKS (read only)
-- Run in phpMyAdmin AFTER every script in database/RUN-ORDER.md.
-- Every query is a SELECT: nothing is changed.
-- Each query says what a good result looks like. Most are written so that
-- a good result is "no rows": any row returned names what is missing.
-- ============================================


-- --------------------------------------------
-- 1. Server
-- Good: a version containing "MariaDB" (11.x on the live server).
-- --------------------------------------------
SELECT VERSION() AS ServerVersion;


-- --------------------------------------------
-- 2. Every expected table exists
-- Good: no rows. A row is a table that is missing.
-- --------------------------------------------
SELECT e.TableName AS MissingTable
FROM (
              SELECT 'Announcements' AS TableName UNION ALL SELECT 'ApprovalLogs' UNION ALL SELECT 'AuditLog'
    UNION ALL SELECT 'Categories' UNION ALL SELECT 'Clubs' UNION ALL SELECT 'EmailBroadcasts'
    UNION ALL SELECT 'EmailLog' UNION ALL SELECT 'EmailQueue' UNION ALL SELECT 'EmailTemplates'
    UNION ALL SELECT 'GalleryPhotos' UNION ALL SELECT 'JobRuns' UNION ALL SELECT 'LoginAttempts'
    UNION ALL SELECT 'Matches' UNION ALL SELECT 'NotificationDigest' UNION ALL SELECT 'NotificationEvents'
    UNION ALL SELECT 'NotificationRecipients' UNION ALL SELECT 'Notifications' UNION ALL SELECT 'PasswordResets'
    UNION ALL SELECT 'Permissions' UNION ALL SELECT 'PlayerPerformanceTracker' UNION ALL SELECT 'PlayerPointsHistory'
    UNION ALL SELECT 'PlayerRankingHistory' UNION ALL SELECT 'PlayerRegistrations' UNION ALL SELECT 'RequestLimits' UNION ALL SELECT 'RequestIds'
    UNION ALL SELECT 'ResultsApprovalQueue' UNION ALL SELECT 'RoundPoints' UNION ALL SELECT 'Settings'
    UNION ALL SELECT 'Sponsors' UNION ALL SELECT 'StaffRoles' UNION ALL SELECT 'SystemSettings'
    UNION ALL SELECT 'TemplateAttachments' UNION ALL SELECT 'TournamentCategories' UNION ALL SELECT 'TournamentPhotos'
    UNION ALL SELECT 'Tournaments' UNION ALL SELECT 'TwoFactorBackupCodes' UNION ALL SELECT 'UserActivityLogs'
    UNION ALL SELECT 'UserPermissions' UNION ALL SELECT 'Users'
) e
LEFT JOIN INFORMATION_SCHEMA.TABLES t
       ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = e.TableName
WHERE t.TABLE_NAME IS NULL;


-- --------------------------------------------
-- 3. Every column the scripts add exists
-- Good: no rows. A row is a column that is missing (and names the script).
-- --------------------------------------------
SELECT e.TableName, e.ColumnName, e.Script AS AddedBy
FROM (
              SELECT 'Tournaments' AS TableName, 'PosterPath' AS ColumnName, 'tournaments-extra-fields.sql' AS Script
    UNION ALL SELECT 'Tournaments', 'RegistrationUrl', 'tournaments-extra-fields.sql'
    UNION ALL SELECT 'Tournaments', 'RegistrationCloseDate', 'tournaments-extra-fields.sql'
    UNION ALL SELECT 'Tournaments', 'EntryFee', 'tournaments-extra-fields.sql'
    UNION ALL SELECT 'Tournaments', 'StartTime', 'tournaments-extra-fields.sql'
    UNION ALL SELECT 'Tournaments', 'Description', 'tournaments-extra-fields.sql'
    UNION ALL SELECT 'Tournaments', 'ContactName', 'tournaments-extra-fields.sql'
    UNION ALL SELECT 'Tournaments', 'ContactPhone', 'tournaments-extra-fields.sql'
    UNION ALL SELECT 'Users', 'IsSuperAdmin', 'permissions-foundation.sql'
    UNION ALL SELECT 'Users', 'LoginEnabled', 'staff-access.sql'
    UNION ALL SELECT 'Tournaments', 'OrganiserName', 'organiser-name.sql'
    UNION ALL SELECT 'Users', 'TwoFactorSecret', 'two-step.sql'
    UNION ALL SELECT 'Users', 'TwoFactorEnabledDate', 'two-step.sql'
    UNION ALL SELECT 'Users', 'TwoFactorRequired', 'two-step.sql'
    UNION ALL SELECT 'Users', 'TwoFactorLastStep', 'two-step.sql'
    UNION ALL SELECT 'EmailLog', 'AttachmentsJson', 'notifications.sql'
    UNION ALL SELECT 'EmailBroadcasts', 'AttachmentsJson', 'notifications.sql'
    UNION ALL SELECT 'Categories', 'IsActive', 'grading-settings.sql'
    UNION ALL SELECT 'PlayerRegistrations', 'TermsVersion', 'terms-acceptance.sql'
    UNION ALL SELECT 'PlayerRegistrations', 'TermsAcceptedDate', 'terms-acceptance.sql'
    UNION ALL SELECT 'Users', 'TermsVersion', 'terms-acceptance.sql'
    UNION ALL SELECT 'Users', 'TermsAcceptedDate', 'terms-acceptance.sql'
    UNION ALL SELECT 'Users', 'IsRemoved', 'people.sql'
    UNION ALL SELECT 'Users', 'RemovedDate', 'people.sql'
    UNION ALL SELECT 'Users', 'RemovedByUserId', 'people.sql'
    UNION ALL SELECT 'Tournaments', 'LastResultsReminderDate', 'tournament-reminders.sql'
    UNION ALL SELECT 'Tournaments', 'AutoResultsReminderDate', 'tournament-reminders.sql'
    UNION ALL SELECT 'PlayerPointsHistory', 'CountsTowardSeason', 'points-cutoff.sql'
    UNION ALL SELECT 'EmailBroadcasts', 'TargetType', 'grading-broadcasts.sql'
    UNION ALL SELECT 'EmailBroadcasts', 'TargetGrades', 'grading-broadcasts.sql'
    UNION ALL SELECT 'EmailBroadcasts', 'ScheduledFor', 'grading-broadcasts.sql'
    UNION ALL SELECT 'EmailBroadcasts', 'UpdatedDate', 'grading-broadcasts.sql'
    UNION ALL SELECT 'EmailBroadcasts', 'CancelledDate', 'grading-broadcasts.sql'
    UNION ALL SELECT 'Users', 'SessionVersion', 'security-hardening.sql'
    UNION ALL SELECT 'EmailLog', 'NextAttemptDate', 'schema-fixes.sql'
) e
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
       ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = e.TableName AND c.COLUMN_NAME = e.ColumnName
WHERE c.COLUMN_NAME IS NULL;


-- --------------------------------------------
-- 4. Column definitions the code relies on (schema-fixes.sql and
--    organiser-name.sql)
-- Good: no rows. A row is a column that still has the old definition.
-- --------------------------------------------
SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, 'should allow NULL' AS Problem
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE() AND c.IS_NULLABLE = 'NO'
  AND ((c.TABLE_NAME = 'Users' AND c.COLUMN_NAME IN ('PostCode', 'CategoryId'))
       OR (c.TABLE_NAME = 'Tournaments' AND c.COLUMN_NAME = 'OrganizingClubId'))
UNION ALL
SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, 'should be TEXT NOT NULL'
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'Settings' AND c.COLUMN_NAME = 'SettingValue'
  AND NOT (c.DATA_TYPE = 'text' AND c.IS_NULLABLE = 'NO')
UNION ALL
SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, 'should hold 60 characters'
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'Categories' AND c.COLUMN_NAME = 'Name'
  AND c.CHARACTER_MAXIMUM_LENGTH < 60;


-- --------------------------------------------
-- 5. Every expected index exists (by name)
-- Good: no rows. A row is an index that is missing. (schema-fixes.sql skips
-- an index when another index already starts with the same columns; on the
-- live database none did, so every name below should be present.)
-- --------------------------------------------
SELECT e.TableName, e.IndexName AS MissingIndex
FROM (
              SELECT 'AuditLog' AS TableName, 'idx_auditlog_created' AS IndexName
    UNION ALL SELECT 'AuditLog', 'idx_auditlog_actor'
    UNION ALL SELECT 'AuditLog', 'idx_auditlog_action'
    UNION ALL SELECT 'AuditLog', 'idx_auditlog_target'
    UNION ALL SELECT 'AuditLog', 'idx_auditlog_actor_date'
    UNION ALL SELECT 'PlayerPointsHistory', 'idx_playerpointshistory_awarded'
    UNION ALL SELECT 'PlayerPointsHistory', 'idx_pph_player_awarded'
    UNION ALL SELECT 'PlayerPointsHistory', 'idx_pph_player_season'
    UNION ALL SELECT 'PlayerPointsHistory', 'idx_pph_match_player'
    UNION ALL SELECT 'PlayerRankingHistory', 'idx_playerrankinghistory_changedate'
    UNION ALL SELECT 'PlayerRankingHistory', 'idx_prh_player_date'
    UNION ALL SELECT 'PlayerRegistrations', 'idx_status'
    UNION ALL SELECT 'PlayerRegistrations', 'idx_registrations_status_date'
    UNION ALL SELECT 'PlayerRegistrations', 'idx_registrations_email'
    UNION ALL SELECT 'Tournaments', 'idx_tournaments_status_date'
    UNION ALL SELECT 'Matches', 'idx_matches_tournament_approved'
    UNION ALL SELECT 'Matches', 'TournamentId'
    UNION ALL SELECT 'Matches', 'Player1Id'
    UNION ALL SELECT 'Matches', 'Partner1Id'
    UNION ALL SELECT 'Matches', 'Player2Id'
    UNION ALL SELECT 'Matches', 'Partner2Id'
    UNION ALL SELECT 'Users', 'idx_users_active_removed_grade'
    UNION ALL SELECT 'Users', 'idx_users_unsubscribe'
    UNION ALL SELECT 'Users', 'idx_users_profilephoto'
    UNION ALL SELECT 'Users', 'Email'
    UNION ALL SELECT 'Users', 'PlayerId'
    UNION ALL SELECT 'PlayerPerformanceTracker', 'PlayerId'
    UNION ALL SELECT 'GalleryPhotos', 'idx_gallery_tournament_order'
    UNION ALL SELECT 'EmailQueue', 'idx_queue_status'
    UNION ALL SELECT 'EmailQueue', 'idx_emailqueue_user'
    UNION ALL SELECT 'EmailQueue', 'idx_emailqueue_email'
    UNION ALL SELECT 'EmailLog', 'idx_emaillog_status_priority'
    UNION ALL SELECT 'EmailLog', 'idx_emaillog_status_sent'
    UNION ALL SELECT 'EmailLog', 'idx_emaillog_toemail'
    UNION ALL SELECT 'EmailBroadcasts', 'idx_broadcasts_status_scheduled'
    UNION ALL SELECT 'LoginAttempts', 'idx_email_time'
    UNION ALL SELECT 'LoginAttempts', 'idx_loginattempts_ip_time'
    UNION ALL SELECT 'ApprovalLogs', 'idx_approvallogs_type_ref'
    UNION ALL SELECT 'UserActivityLogs', 'idx_activitylogs_target_date'
    UNION ALL SELECT 'RequestLimits', 'IX_RequestLimits_Lookup'
) e
LEFT JOIN (SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()) s
       ON s.TABLE_NAME = e.TableName AND s.INDEX_NAME = e.IndexName
WHERE s.INDEX_NAME IS NULL;


-- --------------------------------------------
-- 6. No exact duplicate indexes left
-- Good: no rows. A row is a table with two indexes on exactly the same
-- columns.
-- --------------------------------------------
SELECT a.TABLE_NAME, a.INDEX_NAME, b.INDEX_NAME AS SameColumnsAs, a.cols AS IndexColumns
FROM (SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
      FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
      GROUP BY TABLE_NAME, INDEX_NAME) a
JOIN (SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
      FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
      GROUP BY TABLE_NAME, INDEX_NAME) b
  ON b.TABLE_NAME = a.TABLE_NAME AND b.cols = a.cols AND b.INDEX_NAME < a.INDEX_NAME;


-- --------------------------------------------
-- 7. Engines and collations
-- Good: no rows. A row is a table that is not InnoDB, or a table or text
-- column that is not utf8mb4_unicode_ci (none may still use
-- utf8mb4_uca1400_ai_ci).
-- --------------------------------------------
SELECT t.TABLE_NAME, t.ENGINE, t.TABLE_COLLATION, NULL AS COLUMN_NAME
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
  AND (t.ENGINE <> 'InnoDB' OR t.TABLE_COLLATION <> 'utf8mb4_unicode_ci')
UNION ALL
SELECT c.TABLE_NAME, NULL, c.COLLATION_NAME, c.COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLLATION_NAME IS NOT NULL
  AND c.COLLATION_NAME <> 'utf8mb4_unicode_ci';


-- --------------------------------------------
-- 8. Exactly one Super Admin, and it is KBFUK001
-- Good: one row: PlayerId KBFUK001, IsActive 1, HasPassword 1.
-- --------------------------------------------
SELECT UserId, PlayerId, FirstName, LastName, IsActive, LoginEnabled,
       (PasswordHash IS NOT NULL AND PasswordHash <> '') AS HasPassword
FROM Users
WHERE IsSuperAdmin = 1;


-- --------------------------------------------
-- 9. Every staff account has permissions
-- Staff = an old admin role (MainAdmin, ZonalAdmin, ClubAdmin) or a staff
-- label, active, and not a Super Admin.
-- Good: no rows. A row is a staff member who would be refused at sign-in
-- because they have no permission ticked.
-- --------------------------------------------
SELECT u.UserId, u.PlayerId, u.FirstName, u.LastName, u.Role
FROM Users u
WHERE u.IsActive = 1 AND u.IsSuperAdmin = 0 AND u.IsRemoved = 0
  AND (u.Role IN ('MainAdmin', 'ZonalAdmin', 'ClubAdmin')
       OR EXISTS (SELECT 1 FROM StaffRoles sr WHERE sr.UserId = u.UserId))
  AND NOT EXISTS (SELECT 1 FROM UserPermissions up WHERE up.UserId = u.UserId);


-- --------------------------------------------
-- 10. The account conversion ran once
-- Good: one row per converted account (the Super Admin plus every old
-- MainAdmin, ZonalAdmin and ClubAdmin), all with the same CreatedDate
-- (written by one statement).
-- --------------------------------------------
SELECT COUNT(*) AS ConvertedAccounts, MIN(CreatedDate) AS FirstRun, MAX(CreatedDate) AS LastRun
FROM AuditLog
WHERE Action = 'access_converted';


-- --------------------------------------------
-- 11. Settings needed by the site
-- Good: no rows. A row is a setting that is missing (the site then uses a
-- built-in default and logs it).
-- --------------------------------------------
SELECT e.SettingKey AS MissingSetting
FROM (
              SELECT 'site_base_url' AS SettingKey UNION ALL SELECT 'two_factor_policy'
    UNION ALL SELECT 'email_daily_limit' UNION ALL SELECT 'email_reserve_important'
    UNION ALL SELECT 'broadcast_batch_size' UNION ALL SELECT 'whatsapp_link'
    UNION ALL SELECT 'term_start_months' UNION ALL SELECT 'term_reset_floor_trigger'
    UNION ALL SELECT 'term_reset_floor_value' UNION ALL SELECT 'last_term_reset'
    UNION ALL SELECT 'contact_email' UNION ALL SELECT 'security_email'
    UNION ALL SELECT 'session_timeout_minutes' UNION ALL SELECT 'audit_log_retention_days'
    UNION ALL SELECT 'terms_version' UNION ALL SELECT 'results_reminder_days'
    UNION ALL SELECT 'close_to_promotion_points' UNION ALL SELECT 'email_reserve_normal'
    UNION ALL SELECT 'retention_ip_days' UNION ALL SELECT 'retention_email_body_days'
    UNION ALL SELECT 'retention_rejected_registration_days' UNION ALL SELECT 'file_cleanup_mode'
) e
LEFT JOIN Settings s ON s.SettingKey = e.SettingKey
WHERE s.SettingKey IS NULL;


-- --------------------------------------------
-- 12. Broadcast statuses
-- Good: only draft, scheduled, sending, paused, completed or cancelled
-- (no 'queued' and nothing else).
-- --------------------------------------------
SELECT Status, COUNT(*) AS Broadcasts
FROM EmailBroadcasts
GROUP BY Status;


-- --------------------------------------------
-- 13. Tournament statuses
-- Good: only Pending, Approved, Rejected, Completed or Cancelled.
-- --------------------------------------------
SELECT Status, COUNT(*) AS Tournaments
FROM Tournaments
GROUP BY Status;
