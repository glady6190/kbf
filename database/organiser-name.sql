-- ============================================
-- KBF-UK: STAGE 3a - FREE-TEXT ORGANISER NAME
-- Safe to run more than once. Never drops or deletes anything.
--
-- 1. Tournaments.OrganiserName (VARCHAR(150), NULL)
-- 2. Fill empty OrganiserName values: the linked club's Name when the
--    tournament has a club (OrganizingClubId), otherwise the creator's
--    first and last name.
-- 3. Make Tournaments.OrganizingClubId nullable (new tournaments no longer
--    need a club).
--
-- Tournament ownership now comes from Tournaments.CreatedByAdminId.
-- ============================================

-- 1. New column
ALTER TABLE Tournaments
    ADD COLUMN IF NOT EXISTS OrganiserName VARCHAR(150) NULL;

-- 2a. Fill from the linked club's name
UPDATE Tournaments t
JOIN Clubs c ON c.ClubId = t.OrganizingClubId
SET t.OrganiserName = LEFT(TRIM(c.Name), 150)
WHERE (t.OrganiserName IS NULL OR TRIM(t.OrganiserName) = '')
  AND t.OrganizingClubId IS NOT NULL
  AND c.Name IS NOT NULL AND TRIM(c.Name) <> '';

-- 2b. Otherwise fill from the creator's first and last name
UPDATE Tournaments t
JOIN Users u ON u.UserId = t.CreatedByAdminId
SET t.OrganiserName = LEFT(TRIM(CONCAT(COALESCE(u.FirstName, ''), ' ', COALESCE(u.LastName, ''))), 150)
WHERE (t.OrganiserName IS NULL OR TRIM(t.OrganiserName) = '')
  AND TRIM(CONCAT(COALESCE(u.FirstName, ''), ' ', COALESCE(u.LastName, ''))) <> '';

-- 3. Make OrganizingClubId nullable, only when it is currently NOT NULL.
--    The column keeps its existing type, default and comment; foreign keys
--    are not touched. The table is found whatever the case of its stored
--    name, and the change uses that stored name.
SET @kbf_tbl := (
    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'tournaments'
    LIMIT 1
);
SET @kbf_club_col_type := (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = @kbf_tbl
      AND COLUMN_NAME = 'OrganizingClubId'
      AND IS_NULLABLE = 'NO'
);
SET @kbf_club_default := (
    SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @kbf_tbl AND COLUMN_NAME = 'OrganizingClubId'
);
SET @kbf_club_comment := (
    SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @kbf_tbl AND COLUMN_NAME = 'OrganizingClubId'
);

SET @kbf_sql := IF(
    @kbf_club_col_type IS NULL,
    'SELECT ''OrganizingClubId is already nullable'' AS info',
    CONCAT('ALTER TABLE `', @kbf_tbl, '` MODIFY COLUMN OrganizingClubId ', @kbf_club_col_type, ' NULL',
        IF(@kbf_club_default IS NULL OR @kbf_club_default = 'NULL', '', CONCAT(' DEFAULT ', @kbf_club_default)),
        IF(@kbf_club_comment IS NULL OR @kbf_club_comment = '', '', CONCAT(' COMMENT ', QUOTE(@kbf_club_comment))))
);

PREPARE kbf_stmt FROM @kbf_sql;
EXECUTE kbf_stmt;
DEALLOCATE PREPARE kbf_stmt;
