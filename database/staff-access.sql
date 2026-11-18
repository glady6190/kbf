-- ============================================
-- KBF-UK: Per-user permissions - Stage 2 (Staff and access pages)
-- Run in phpMyAdmin (MariaDB) after permissions-foundation.sql.
-- Safe to run more than once: ADD COLUMN IF NOT EXISTS skips a column that is
-- already there. Nothing is dropped or deleted.
--
-- LoginEnabled lets a Super Admin stop someone signing in without removing
-- their permission ticks or their player profile. Every existing account
-- keeps signing in exactly as now (default 1). The login check itself starts
-- using this column in Stage 3.
-- ============================================

ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS LoginEnabled TINYINT(1) NOT NULL DEFAULT 1;
