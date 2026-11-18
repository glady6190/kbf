-- ============================================
-- KBF-UK: PEOPLE (PART 1) - REMOVING A PLAYER AT THEIR REQUEST
-- Run in phpMyAdmin (MariaDB). Safe to run more than once.
-- Never drops or deletes anything: ADD COLUMN IF NOT EXISTS only.
--
-- Run this BEFORE uploading the People part 1 PHP files: the public pages,
-- reports and jobs now leave out Users.IsRemoved = 1, so they need the column.
--
-- Users.IsRemoved: 1 when a Super Admin removed the person at their request.
-- Their personal details are cleared; the KBF ID, grade and points history are
-- kept and shown as "Former member". It cannot be undone.
-- ============================================

ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS IsRemoved TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS RemovedDate DATETIME NULL,
    ADD COLUMN IF NOT EXISTS RemovedByUserId INT NULL;
