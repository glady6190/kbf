-- ============================================
-- KBF-UK: Per-user permissions - Stage 1 foundation
-- Run in phpMyAdmin (MariaDB). Safe to run more than once:
--   * CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS / INSERT IGNORE
--   * Each account is converted only once: every conversion step skips users
--     that already have an 'access_converted' row in AuditLog, so a second run
--     never re-grants access that was removed after the first run.
-- Nothing is dropped or deleted. Users.Role is left unchanged.
--
-- The 12 permission keys and the three role presets MUST match
-- api/permissions.php exactly.
--
-- NOTE: UserPermissions.UserId is INT to match Users.UserId. If Users.UserId is
-- INT UNSIGNED (or another type), change UserPermissions.UserId to the same
-- type before running, or the foreign key will be refused.
-- ============================================


-- --------------------------------------------
-- 1. Users: Super Admin flag
-- --------------------------------------------
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS IsSuperAdmin TINYINT(1) NOT NULL DEFAULT 0;


-- --------------------------------------------
-- 2. Permissions (the list of tickable permissions)
-- Adding staff, changing access, impersonation and security settings are NOT
-- permissions: they belong to Super Admins only.
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS Permissions (
    PermissionKey VARCHAR(60)  NOT NULL,
    Label         VARCHAR(120) NOT NULL,
    GroupName     VARCHAR(40)  NOT NULL,
    SortOrder     INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (PermissionKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO Permissions (PermissionKey, Label, GroupName, SortOrder) VALUES
    ('players.approve_registrations', 'Approve player registrations',               'Players',     10),
    ('players.view_export',           'View and export the player list',            'Players',     20),
    ('players.edit_details',          'Edit player details (not grade)',            'Players',     30),
    ('players.change_grades',         'Change player grades',                       'Players',     40),
    ('players.contact_details',       'See player emails and phone numbers',        'Players',     50),
    ('tournaments.organise_own',      'Organise their own tournaments',             'Tournaments', 110),
    ('tournaments.approve',           'Approve tournaments and results',            'Tournaments', 120),
    ('tournaments.manage_all',        'Manage all tournaments and results',         'Tournaments', 130),
    ('content.own_news_gallery',      'News and gallery for their own tournaments', 'Content',     210),
    ('content.all_news_gallery',      'All news and gallery',                       'Content',     220),
    ('content.sponsors',              'Sponsors',                                   'Content',     230),
    ('content.broadcasts',            'Email broadcasts',                           'Content',     240);


-- --------------------------------------------
-- 3. UserPermissions (permissions ticked per person)
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS UserPermissions (
    UserId          INT         NOT NULL,
    PermissionKey   VARCHAR(60) NOT NULL,
    GrantedByUserId INT         NULL DEFAULT NULL,
    GrantedDate     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (UserId, PermissionKey),
    KEY idx_userpermissions_key (PermissionKey),
    CONSTRAINT fk_userpermissions_user
        FOREIGN KEY (UserId) REFERENCES Users (UserId),
    CONSTRAINT fk_userpermissions_permission
        FOREIGN KEY (PermissionKey) REFERENCES Permissions (PermissionKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 4. StaffRoles (labels only: MainAdmin, ZonalAdmin, Organiser)
-- Permissions decide what people can do.
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS StaffRoles (
    UserId  INT         NOT NULL,
    RoleKey VARCHAR(20) NOT NULL,
    PRIMARY KEY (UserId, RoleKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------
-- 5. AuditLog
-- ActorUserId 0 = system (e.g. this conversion) or unknown.
-- ActingAsUserId is for impersonation later.
-- --------------------------------------------
CREATE TABLE IF NOT EXISTS AuditLog (
    AuditId        INT          NOT NULL AUTO_INCREMENT,
    ActorUserId    INT          NOT NULL,
    ActingAsUserId INT          NULL DEFAULT NULL,
    Action         VARCHAR(60)  NOT NULL,
    TargetType     VARCHAR(40)  NULL DEFAULT NULL,
    TargetId       INT          NULL DEFAULT NULL,
    Details        TEXT         NULL DEFAULT NULL,
    IpAddress      VARCHAR(45)  NOT NULL DEFAULT '',
    CreatedDate    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (AuditId),
    KEY idx_auditlog_created (CreatedDate),
    KEY idx_auditlog_actor (ActorUserId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 6. CONVERT EXISTING ACCOUNTS (active or not)
-- "Not yet converted" = no 'access_converted' AuditLog row for the user.
-- The Super Admin (PlayerId KBFUK001) gets the flag only, no labels or
-- permission rows. Players get nothing.
-- ============================================

-- 6a. Super Admin
UPDATE Users u
SET u.IsSuperAdmin = 1
WHERE u.PlayerId = 'KBFUK001'
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );

-- 6b. Labels
-- MainAdmin label (every MainAdmin except the Super Admin)
INSERT IGNORE INTO StaffRoles (UserId, RoleKey)
SELECT u.UserId, 'MainAdmin'
FROM Users u
WHERE u.Role = 'MainAdmin'
  AND NOT (u.PlayerId <=> 'KBFUK001')
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );

-- ZonalAdmin label
INSERT IGNORE INTO StaffRoles (UserId, RoleKey)
SELECT u.UserId, 'ZonalAdmin'
FROM Users u
WHERE u.Role = 'ZonalAdmin'
  AND NOT (u.PlayerId <=> 'KBFUK001')
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );

-- Organiser label (every ClubAdmin, and every ZonalAdmin with a club)
INSERT IGNORE INTO StaffRoles (UserId, RoleKey)
SELECT u.UserId, 'Organiser'
FROM Users u
WHERE (u.Role = 'ClubAdmin'
       OR (u.Role = 'ZonalAdmin' AND u.ClubId IS NOT NULL AND u.ClubId <> 0))
  AND NOT (u.PlayerId <=> 'KBFUK001')
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );

-- 6c. Permissions
-- MainAdmin: all 12
INSERT IGNORE INTO UserPermissions (UserId, PermissionKey, GrantedByUserId, GrantedDate)
SELECT u.UserId, p.PermissionKey, NULL, NOW()
FROM Users u
CROSS JOIN Permissions p
WHERE u.Role = 'MainAdmin'
  AND NOT (u.PlayerId <=> 'KBFUK001')
  AND p.PermissionKey IN (
      'players.approve_registrations', 'players.view_export', 'players.edit_details',
      'players.change_grades', 'players.contact_details',
      'tournaments.organise_own', 'tournaments.approve', 'tournaments.manage_all',
      'content.own_news_gallery', 'content.all_news_gallery', 'content.sponsors', 'content.broadcasts'
  )
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );

-- ZonalAdmin: the three player permissions
INSERT IGNORE INTO UserPermissions (UserId, PermissionKey, GrantedByUserId, GrantedDate)
SELECT u.UserId, p.PermissionKey, NULL, NOW()
FROM Users u
CROSS JOIN Permissions p
WHERE u.Role = 'ZonalAdmin'
  AND NOT (u.PlayerId <=> 'KBFUK001')
  AND p.PermissionKey IN (
      'players.approve_registrations', 'players.view_export', 'players.edit_details'
  )
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );

-- Organiser preset: every ClubAdmin, and every ZonalAdmin with a club
INSERT IGNORE INTO UserPermissions (UserId, PermissionKey, GrantedByUserId, GrantedDate)
SELECT u.UserId, p.PermissionKey, NULL, NOW()
FROM Users u
CROSS JOIN Permissions p
WHERE (u.Role = 'ClubAdmin'
       OR (u.Role = 'ZonalAdmin' AND u.ClubId IS NOT NULL AND u.ClubId <> 0))
  AND NOT (u.PlayerId <=> 'KBFUK001')
  AND p.PermissionKey IN (
      'tournaments.organise_own', 'content.own_news_gallery'
  )
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );

-- 6d. One audit row per converted account (run last; marks the account converted)
-- Super Admin
INSERT INTO AuditLog (ActorUserId, ActingAsUserId, Action, TargetType, TargetId, Details, IpAddress, CreatedDate)
SELECT 0, NULL, 'access_converted', 'user', u.UserId,
       CONCAT('Old role: ', COALESCE(u.Role, '(none)'), '; converted to Super Admin'),
       'migration', NOW()
FROM Users u
WHERE u.PlayerId = 'KBFUK001'
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );

-- Every other MainAdmin, ZonalAdmin and ClubAdmin
INSERT INTO AuditLog (ActorUserId, ActingAsUserId, Action, TargetType, TargetId, Details, IpAddress, CreatedDate)
SELECT 0, NULL, 'access_converted', 'user', u.UserId,
       CONCAT('Old role: ', u.Role),
       'migration', NOW()
FROM Users u
WHERE u.Role IN ('MainAdmin', 'ZonalAdmin', 'ClubAdmin')
  AND NOT (u.PlayerId <=> 'KBFUK001')
  AND NOT EXISTS (
      SELECT 1 FROM AuditLog a
      WHERE a.Action = 'access_converted' AND a.TargetType = 'user' AND a.TargetId = u.UserId
  );
