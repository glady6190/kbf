# Database scripts: run order and checks

Run these scripts in phpMyAdmin (Import, or paste into the SQL tab), on the
live database, **in this exact order**, before the new PHP files are uploaded.
Every script is safe to run again: if one stops with an error, fix the cause
and run that same script again, then carry on with the next one.

The starting structure of the live database is in `base-schema-live.sql`
(structure only, MariaDB 11.8, every table InnoDB). Do not run that file: it
is the record of where the migrations start from.

## Before you start

1. Take a full backup of the database (phpMyAdmin, Export, "Custom", structure
   and data). Keep it until the new site has worked for a few days.
2. Switch maintenance mode on in Site settings, so nobody saves anything while
   the tables change.
3. In Hostinger's cron jobs, pause `jobs/broadcast-sender.php`,
   `jobs/ranking-calculator.php` and `jobs/term-reset.php` until the end.
4. In phpMyAdmin, check that the server says MariaDB (the scripts use
   MariaDB-only `ADD COLUMN IF NOT EXISTS`).
5. Check that KBF ID `KBFUK001` is the right person, active and with a
   password: that account becomes the only Super Admin.

## The order

| # | Script | Before it | After it |
|---|---|---|---|
| 1 | `sponsors.sql` | Nothing special. | A `Sponsors` table exists. |
| 2 | `tournaments-extra-fields.sql` | Nothing special. | `Tournaments` has PosterPath, RegistrationUrl, RegistrationCloseDate, EntryFee, StartTime, Description, ContactName and ContactPhone. Existing tournaments are unchanged. |
| 3 | `permissions-foundation.sql` | The backup is done. `Users` is InnoDB and `Users.UserId` is int(11) (both true on live). | `Users` has IsSuperAdmin. Tables `Permissions` (12 rows), `UserPermissions`, `StaffRoles` and `AuditLog` exist. Exactly one user has IsSuperAdmin = 1 (KBFUK001). AuditLog has one `access_converted` row per old MainAdmin, ZonalAdmin and ClubAdmin, plus the Super Admin. A later re-run converts nobody. |
| 4 | `staff-access.sql` | Script 3 has finished. | `Users` has LoginEnabled, 1 for everybody. |
| 5 | `organiser-name.sql` | Nothing special. | `Tournaments` has OrganiserName, filled in for every tournament. `OrganizingClubId` now allows NULL (its type is unchanged). |
| 6 | `two-step.sql` | Nothing special. | `Users` has TwoFactorSecret, TwoFactorEnabledDate, TwoFactorRequired and TwoFactorLastStep. A `TwoFactorBackupCodes` table exists. Setting `two_factor_policy` is `off`. Leave it off until `TWO_FACTOR_KEY` is in `.env`. |
| 7 | `email-engine.sql` | Script 3 has finished (Permissions). | Tables `EmailLog` and `JobRuns` exist. Settings email_daily_limit, email_reserve_important, email_paused_until, email_log_retention_days and broadcast_batch_size exist. Permissions has six `control.*` rows (18 in total). |
| 8 | `notifications.sql` | Script 7 has finished (EmailLog). | Tables `NotificationEvents` (19 rows), `NotificationRecipients`, `NotificationDigest`, `EmailTemplates` and `TemplateAttachments` exist. `EmailLog` and `EmailBroadcasts` have AttachmentsJson. Setting `whatsapp_link` exists. |
| 9 | `grading-settings.sql` | Nothing special. | `Categories` has IsActive, 1 for every grade. Settings term_start_months, term_reset_floor_trigger, term_reset_floor_value and last_term_reset exist (last_term_reset empty). |
| 10 | `site-settings.sql` | Nothing special. | The 25 site, upload, maintenance and sign-in settings exist. `default_grade_id` exists if there is a grade with short name D. |
| 11 | `reports-activity.sql` | Script 3 has finished (AuditLog). | Setting audit_log_retention_days (730) exists. Indexes idx_auditlog_action, idx_playerpointshistory_awarded, idx_playerrankinghistory_changedate exist (PlayerRegistrations already had an index on SubmittedDate, so none is added there). |
| 12 | `terms-acceptance.sql` | Nothing special. | `PlayerRegistrations` and `Users` have TermsVersion and TermsAcceptedDate (empty for existing rows). Setting terms_version is 2026-11. |
| 13 | `people.sql` | Nothing special. | `Users` has IsRemoved (0 for everybody), RemovedDate and RemovedByUserId. |
| 14 | `tournament-reminders.sql` | Script 8 has finished (NotificationEvents). | `Tournaments` has LastResultsReminderDate and AutoResultsReminderDate. On live, Status is text, so the script reports that no change is needed. NotificationEvents has `results_overdue` (20 rows). Setting results_reminder_days exists. |
| 15 | `points-cutoff.sql` | Nothing special. | `PlayerPointsHistory` has CountsTowardSeason, 1 for every existing row. |
| 16 | `grading-broadcasts.sql` | Script 8 has finished. | `EmailBroadcasts` has TargetGrades, ScheduledFor, UpdatedDate and CancelledDate. TargetType and Status are text on live, so the script reports that no change is needed. Setting close_to_promotion_points exists. |
| 17 | `security-hardening.sql` | Nothing special. | `Users` has SessionVersion, 0 for everybody. A `RequestLimits` table exists. |
| 18 | `schema-fixes.sql` | All of 1 to 17 have finished (it uses their columns). The backup is done: this script rebuilds eight tables to change their collation. On a very large table this can take a minute. | See below. |
| 19 | `request-ids.sql` | Script 18 has finished. | A `RequestIds` table exists (empty). Pages that create news posts, tournaments, players or results can no longer create a duplicate by sending the same form twice. |
| 20 | `data-retention.sql` | Script 18 has finished (`Settings.SettingValue` is TEXT). | Settings retention_ip_days (90), retention_email_body_days (30), retention_rejected_registration_days (365) and file_cleanup_mode (`report`) exist. Nothing else changes: the nightly job applies them from its next run (see below). |
| 21 | `news-editor.sql` | Nothing special. | `Announcements` has ContentFormat (`text` for every existing post), PostStatus (`published` for every existing post), ScheduledFor, UpdatedDate and ShareImagePath, and IsPinned is NOT NULL (0 where it was empty). An `AnnouncementPhotos` table exists (empty). `Tournaments` and `Users` have ShareImagePath (empty). Existing posts show exactly as before. |
| 22 | `zonal-admin-preset.sql` | Script 3 has finished (UserPermissions, StaffRoles, AuditLog). | Zonal Admins who had exactly the old preset (approve registrations, view and export players, edit player details) now have only "Approve player registrations"; each has one `access_changed` row in AuditLog. Its last query lists any Zonal Admin whose permissions differ from the new preset: check each one by hand on People, Staff and access. A re-run changes nobody. |
| 23 | `email-verification.sql` | Script 8 has finished (NotificationEvents). The new PHP files may already be uploaded: until this script has run, the registration form works exactly as before, with no code step. | An `EmailVerificationCodes` table exists (empty). NotificationEvents has `registration_code` (important, locked on). Settings job_checkin_broadcast_sender, job_checkin_ranking_calculator and job_checkin_term_reset exist (empty until each job next runs). The registration form now asks for a 6-digit code sent to the email address before it can be submitted. The script's last three queries should each return 1. |

### After `schema-fixes.sql`

- ApprovalLogs, EmailBroadcasts, EmailQueue, LoginAttempts, PasswordResets,
  PlayerRegistrations, RoundPoints and Settings now use utf8mb4_unicode_ci,
  like every other table. Their data is unchanged.
- `Settings.SettingValue` is TEXT. Every setting kept its value.
- `Users.PostCode` and `Users.CategoryId` allow NULL. `Categories.Name` holds
  60 characters.
- Old broadcasts: any `queued` or unknown status is now `completed` (nothing
  left to send) or `paused` (recipients still waiting). Check each paused one
  on the Broadcasts page and resume or cancel it. The column default is
  `draft`.
- The new indexes exist, and these exact duplicate indexes are gone (another
  index with the same columns stays on each table):
  - Clubs: idx_isactive
  - Matches: idx_tournament, idx_player1, idx_player2, idx_category
  - PasswordResets: idx_token
  - PlayerPerformanceTracker: idx_player, idx_category
  - ResultsApprovalQueue: idx_tournament
  - Tournaments: idx_organizing_club
  - Users: idx_player_id, idx_isactive

### After `data-retention.sql`

- The first nightly run after the upload blanks IP addresses older than 90
  days, clears the text of sent and failed emails older than 30 days, clears
  rejected applications decided more than 365 days ago (up to 500 a night)
  and clears the copies of contact details, year of birth, photo and IP
  address kept on approved applications. Its summary on the Overview page
  ("Scheduled jobs") says how many.
- Unused photo and news image files are only listed at first
  (`file_cleanup_mode` = `report`): the count is in the job summary and the
  list in `storage/logs/file-cleanup-YYYY-MM-DD.log` on the server. Read that
  list before a Super Admin chooses "Delete them" on the Security page.

## After every script

1. Run `migration-checks.sql`. Each query says what a good result looks like.
   Most should return no rows.
2. Upload the new PHP files.
3. Check `.env` against `.env.example`. Every REQUIRED key must be set,
   including `TWO_FACTOR_KEY` and `SMTP_PASSWORD`.
4. Sign in as the Super Admin and as one other staff member.
5. Switch the cron jobs back on (see the time zone notes below), then switch
   maintenance mode off.
6. One-off, after the move: turn text that was stored HTML-encoded (for
   example `O&#039;Connor` instead of O'Connor) back into plain text. On the
   server, run `php jobs/decode-stored-text.php --dry-run` first to see the
   counts and examples, then `php jobs/decode-stored-text.php`. It is safe
   to run twice: each finished column is recorded and skipped next time,
   and only values that look exactly like encoded text are changed. Until it
   has run, a few staff searches may not find older names with accents or
   apostrophes.
7. One-off, after the move: make the display versions and thumbnails of the
   images uploaded before this change. On the server, run
   `php jobs/make-thumbnails.php` (Hostinger: SSH, or a one-off cron job)
   until it reports "0 still to do". Each run handles up to 200 images in
   about 50 seconds and can be stopped and run again at any time; nothing
   is lost if it stops halfway. Until an image has its versions, pages show
   the original instead. After `news-editor.sql` the same job also makes
   the share pictures (link previews) of existing posts, tournaments and
   players, and the default share picture; until then shared links use the
   default picture.
8. One-off, after the move: remove emoji and symbols (such as the trophy)
   from the start of existing news post titles. On the server, run
   `php jobs/clean-titles.php --dry-run` first to see the count and
   examples, then `php jobs/clean-titles.php`. It reports how many titles
   it changed. It is safe to run twice: a cleaned title is not changed
   again. New promotion posts are already created without an emoji.

Do not upload the `tools/` and `docs/` folders, or `tailwind.config.js`:
they are only used to build the stylesheet (see `docs/BUILD.md`), and the
site blocks them anyway.

## Time zone notes

The site now works in UK time (Europe/London) in PHP and in every database
session. The database server itself stays on UTC.

- Times stored before the change were written in UTC and are left as they
  are. A summer timestamp from before the change can look up to one hour
  early. Dates (tournaments, seasons) are not affected.
- Scheduled broadcasts saved before the change start up to one hour early in
  summer. Check the scheduled list on the Broadcasts page after uploading.
- Hostinger's cron schedules stay in server time (UTC). The every-minute
  email sender needs no change. Any daily job set between 23:00 and 00:00 UTC
  now runs after midnight UK time in summer (so "today" is already the next
  day). The season reset (`jobs/term-reset.php`) is best run shortly after
  midnight UK time all year: a schedule such as 00:10 UTC does that (00:10 in
  winter, 01:10 in summer).
