-- ============================================
-- KBF-UK: DATA RETENTION SETTINGS (QUAL-08, SEC-15)
-- Run in phpMyAdmin (MariaDB). Safe to run more than once.
-- Never drops or deletes anything; an existing value is kept.
--
-- The nightly job (jobs/ranking-calculator.php) applies these. Super Admins
-- change them on the Security page ("Data retention"). Until a row exists
-- the site uses the same defaults, so nothing breaks before this runs.
--
--   retention_ip_days                     90      IP addresses in the activity
--                                                 log, sign-in attempts,
--                                                 password resets and
--                                                 registrations are blanked
--   retention_email_body_days             30      text of sent and failed
--                                                 emails cleared (log line kept)
--   retention_rejected_registration_days  365     rejected applications: the
--                                                 applicant's details and photo
--                                                 removed (row kept for counts)
--   file_cleanup_mode                     report  unused files in uploads/profiles,
--                                                 uploads/registrations and
--                                                 uploads/announcements are only
--                                                 listed; "delete" deletes them
--
-- The activity log period stays audit_log_retention_days (730).
-- ============================================

INSERT IGNORE INTO Settings (SettingKey, SettingValue, Description) VALUES
    ('retention_ip_days', '90', 'IP addresses older than this many days are blanked by the nightly job'),
    ('retention_email_body_days', '30', 'Text of sent and failed emails older than this many days is cleared by the nightly job'),
    ('retention_rejected_registration_days', '365', 'Rejected applications older than this many days lose their personal details and photo'),
    ('file_cleanup_mode', 'report', 'Unused uploaded photos: report (only list them) or delete');
