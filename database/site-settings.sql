-- ============================================
-- KBF-UK: CONTROL CENTRE STAGE 4 - SITE SETTINGS AND SIGN-IN RULES
-- Run in phpMyAdmin (MariaDB). Safe to run more than once.
-- INSERT IGNORE only: settings that already exist keep their values.
-- Never drops or deletes anything.
--
-- Every key has a fallback in the code equal to today's value, so the site
-- behaves the same before and after this script runs.
-- (whatsapp_link and site_base_url already exist.)
-- ============================================

-- The Settings table's description column is not fixed across installs, so
-- it is used only when a column named Description exists.
SET @kbf_has_description := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'Settings'
      AND COLUMN_NAME = 'Description'
);


-- --------------------------------------------
-- 1. Contact and links, registration, home page, uploads,
--    maintenance mode, sign-in rules
-- --------------------------------------------
SET @kbf_sql := IF(
    @kbf_has_description > 0,
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue, Description) VALUES
        (''contact_email'', ''admin@kbf-uk.org'', ''Footer, contact and sponsorship enquiries''),
        (''security_email'', ''security@kbf-uk.org'', ''Shown in password emails''),
        (''social_facebook'', '''', ''Facebook page, shown in the footer when filled in''),
        (''social_instagram'', '''', ''Instagram page, shown in the footer when filled in''),
        (''registration_open'', ''1'', ''1 = the register page shows the form, 0 = it shows the closed message''),
        (''registration_closed_message'', ''Registration is closed for now. Please check back soon.'', ''Shown instead of the registration form when registration is closed''),
        (''min_player_age'', ''10'', ''Youngest age allowed, checked against the year of birth''),
        (''registration_limit_per_ip_hour'', ''5'', ''Registrations allowed per internet address per hour''),
        (''ticker_speed'', ''50'', ''Pixels per second for the upcoming tournaments bar''),
        (''sponsor_rotate_seconds'', ''4'', ''How long each sponsor stays in the spotlight''),
        (''show_promotions_strip'', ''1'', ''1 = show the promotions strip on the home page''),
        (''upload_mb_photo'', ''5'', ''Player photos, MB''),
        (''upload_mb_news'', ''5'', ''News images, MB''),
        (''upload_mb_gallery'', ''15'', ''Gallery photos, MB''),
        (''upload_mb_poster'', ''4'', ''Tournament posters, MB''),
        (''upload_mb_sponsor'', ''2'', ''Sponsor logos, MB''),
        (''upload_mb_email_attachments'', ''5'', ''Email attachments in total, MB''),
        (''maintenance_mode'', ''0'', ''1 = visitors see the back-soon page (Super Admin only)''),
        (''maintenance_message'', ''We are making some improvements. Please check back shortly.'', ''Shown on the back-soon page''),
        (''session_timeout_minutes'', ''120'', ''Staff sessions end after this many idle minutes''),
        (''session_warning_minutes'', ''5'', ''Minutes of warning before a session ends''),
        (''login_lockout_attempts_email'', ''5'', ''Failed sign-ins before lockout, per email''),
        (''login_lockout_attempts_ip'', ''20'', ''Failed sign-ins before lockout, per internet address''),
        (''login_lockout_minutes'', ''15'', ''How long a lockout lasts, minutes''),
        (''impersonation_minutes'', ''30'', ''Impersonation ends after this many minutes'')',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue) VALUES
        (''contact_email'', ''admin@kbf-uk.org''),
        (''security_email'', ''security@kbf-uk.org''),
        (''social_facebook'', ''''),
        (''social_instagram'', ''''),
        (''registration_open'', ''1''),
        (''registration_closed_message'', ''Registration is closed for now. Please check back soon.''),
        (''min_player_age'', ''10''),
        (''registration_limit_per_ip_hour'', ''5''),
        (''ticker_speed'', ''50''),
        (''sponsor_rotate_seconds'', ''4''),
        (''show_promotions_strip'', ''1''),
        (''upload_mb_photo'', ''5''),
        (''upload_mb_news'', ''5''),
        (''upload_mb_gallery'', ''15''),
        (''upload_mb_poster'', ''4''),
        (''upload_mb_sponsor'', ''2''),
        (''upload_mb_email_attachments'', ''5''),
        (''maintenance_mode'', ''0''),
        (''maintenance_message'', ''We are making some improvements. Please check back shortly.''),
        (''session_timeout_minutes'', ''120''),
        (''session_warning_minutes'', ''5''),
        (''login_lockout_attempts_email'', ''5''),
        (''login_lockout_attempts_ip'', ''20''),
        (''login_lockout_minutes'', ''15''),
        (''impersonation_minutes'', ''30'')'
);

PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;


-- --------------------------------------------
-- 2. Default grade for players added by an admin: the grade whose
--    ShortName is D (nothing is inserted when there is no such grade; the
--    site then uses the lowest-ranked active grade)
-- --------------------------------------------
SET @kbf_sql := IF(
    @kbf_has_description > 0,
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue, Description)
        SELECT ''default_grade_id'', CAST(CategoryId AS CHAR), ''Grade given to players added by an admin''
        FROM Categories WHERE ShortName = ''D'' ORDER BY CategoryId LIMIT 1',
    'INSERT IGNORE INTO Settings (SettingKey, SettingValue)
        SELECT ''default_grade_id'', CAST(CategoryId AS CHAR)
        FROM Categories WHERE ShortName = ''D'' ORDER BY CategoryId LIMIT 1'
);

PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
