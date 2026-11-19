-- ============================================
-- KBF-UK: NEWS EDITOR, LINK PREVIEWS
-- Safe to run more than once. Never drops or deletes anything.
--
-- 1. Announcements: ContentFormat ('text' or 'html'; every existing post
--    is 'text' and keeps showing exactly as before), PostStatus ('draft',
--    'scheduled' or 'published'; every existing post is 'published'),
--    ScheduledFor, IsPinned (NOT NULL, 0 by default), UpdatedDate and
--    ShareImagePath. IsPublished and IsVisible keep working as before:
--    public pages show IsPublished = 1 AND IsVisible = 1; a hidden post is
--    IsVisible = 0. Drafts and scheduled posts are saved with
--    IsPublished = 0.
-- 2. New table AnnouncementPhotos: the extra photos under a post's text
--    (original, display version and thumbnail, caption and order).
-- 3. Tournaments.ShareImagePath and Users.ShareImagePath: the 1200 x 630
--    picture shown when a page is shared (made by the site).
-- ============================================

-- 1. Announcements
ALTER TABLE Announcements
    ADD COLUMN IF NOT EXISTS ContentFormat ENUM('text', 'html') NOT NULL DEFAULT 'text',
    ADD COLUMN IF NOT EXISTS PostStatus ENUM('draft', 'scheduled', 'published') NOT NULL DEFAULT 'published',
    ADD COLUMN IF NOT EXISTS ScheduledFor DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS IsPinned TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS UpdatedDate DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ShareImagePath VARCHAR(255) NULL DEFAULT NULL;

-- IsPinned already existed on live as a nullable column: no NULLs, then
-- NOT NULL DEFAULT 0 (only when it still allows NULL)
UPDATE Announcements SET IsPinned = 0 WHERE IsPinned IS NULL;
SET @kbf_sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Announcements' AND COLUMN_NAME = 'IsPinned'
       AND IS_NULLABLE = 'YES') > 0,
    'ALTER TABLE Announcements MODIFY COLUMN IsPinned TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''Announcements.IsPinned already NOT NULL'' AS info'
);
PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;

-- The scheduled-post check (broadcast sender job) and the staff list tabs
CREATE INDEX IF NOT EXISTS idx_announcements_status_schedule ON Announcements (PostStatus, ScheduledFor);

-- 2. Extra photos of a post
CREATE TABLE IF NOT EXISTS AnnouncementPhotos (
    Id             INT           NOT NULL AUTO_INCREMENT,
    AnnouncementId INT           NOT NULL,
    FilePath       VARCHAR(255)  NOT NULL,
    DisplayPath    VARCHAR(255)  NULL DEFAULT NULL,
    ThumbPath      VARCHAR(255)  NULL DEFAULT NULL,
    Caption        VARCHAR(200)  NULL DEFAULT NULL,
    SortOrder      INT           NOT NULL DEFAULT 0,
    CreatedDate    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (Id),
    KEY idx_announcementphotos_post (AnnouncementId, SortOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Share pictures of tournaments and players
ALTER TABLE Tournaments
    ADD COLUMN IF NOT EXISTS ShareImagePath VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS ShareImagePath VARCHAR(255) NULL DEFAULT NULL;
