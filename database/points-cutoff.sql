-- ============================================
-- KBF-UK: CAREER-ONLY POINTS
-- Run in phpMyAdmin (MariaDB). Safe to run more than once.
-- Never drops or deletes anything.
--
-- PlayerPointsHistory.CountsTowardSeason
--   1 = counts toward season points and the current grade (as before)
--   0 = career points only: the tournament took place on or before the
--       player's most recent grade change, or before the current season
--       started (rule in api/points-rules.php)
-- Existing rows keep 1.
-- ============================================

ALTER TABLE PlayerPointsHistory
    ADD COLUMN IF NOT EXISTS CountsTowardSeason TINYINT(1) NOT NULL DEFAULT 1;
