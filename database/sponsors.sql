-- ============================================
-- KBF-UK: Sponsors table
-- Run once in phpMyAdmin. Safe on a database that does not yet have the table
-- (IF NOT EXISTS makes a second run a no-op instead of an error).
-- ============================================

CREATE TABLE IF NOT EXISTS Sponsors (
    SponsorId     INT          NOT NULL AUTO_INCREMENT,
    Name          VARCHAR(150) NOT NULL,
    LogoPath      VARCHAR(255) NOT NULL,
    WebsiteUrl    VARCHAR(255) NULL DEFAULT NULL,
    Description   VARCHAR(160) NULL DEFAULT NULL,
    DisplayOrder  INT          NOT NULL DEFAULT 0,
    IsActive      TINYINT(1)   NOT NULL DEFAULT 1,
    CreatedDate   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (SponsorId),
    KEY idx_sponsors_active_order (IsActive, DisplayOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
