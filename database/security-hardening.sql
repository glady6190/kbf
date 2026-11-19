-- ============================================
-- KBF-UK: security hardening (audit batch 1: SEC-03, SEC-05)
--
-- Safe to run more than once:
--   * ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS only
--   * never drops or deletes anything
-- Needs MariaDB 10.0.2+ (ADD COLUMN IF NOT EXISTS), as the other scripts.
-- ============================================

-- 1. Users.SessionVersion: "Sign out everywhere".
-- Kept in the session at sign-in and compared on every request; raised on a
-- password change, password reset, temporary password, when a Super Admin
-- turns 2-step off or resets it, and by "Sign out everywhere".
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS SessionVersion INT NOT NULL DEFAULT 0;

-- 2. RequestLimits: request counts for limits (forgot password per email and
-- per IP address, failed reset link lookups per IP address).
-- KeyHash is the SHA-256 of the email (lower case) or IP address.
-- Rows are never removed when a reset token is issued; the site removes rows
-- older than one day.
CREATE TABLE IF NOT EXISTS RequestLimits (
    Id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    Bucket VARCHAR(40) NOT NULL,
    KeyHash CHAR(64) NOT NULL,
    CreatedAt DATETIME NOT NULL,
    PRIMARY KEY (Id),
    KEY IX_RequestLimits_Lookup (Bucket, KeyHash, CreatedAt),
    KEY IX_RequestLimits_Created (CreatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
