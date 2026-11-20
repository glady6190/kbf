-- ============================================
-- KBF-UK: ZONAL ADMIN PRESET (APPROVE AND REJECT ONLY)
-- Safe to run more than once.
--
-- The Zonal Admin preset now gives only players.approve_registrations
-- (approve and reject new player registrations). It used to give three:
-- players.approve_registrations, players.view_export and
-- players.edit_details.
--
-- 1. Everyone with the Zonal Admin label whose permissions are EXACTLY the
--    old three loses players.view_export and players.edit_details. Anyone
--    with any other combination (ticked by hand) is left unchanged.
-- 2. One AuditLog row (access_changed) per person changed.
-- 3. The count changed by parts 1 and 2.
-- 4. Zonal Admins with EXACTLY the old three plus tournaments.organise_own
--    and content.own_news_gallery lose players.view_export and
--    players.edit_details and keep the other three, with one AuditLog row
--    each, then the count changed by this part.
-- 5. A list of Zonal Admins whose permissions are neither exactly the new
--    preset nor exactly the new preset plus the two organiser permissions,
--    to check by hand on People, Staff and access.
--
-- A second run changes nobody: after the first run nobody has the old
-- three (or the old three plus the organiser two) any more. Only UserPermissions rows are removed; nothing else is
-- dropped or deleted.
-- ============================================

-- 1. Who changes: the Zonal Admin label, and exactly the old three
DROP TEMPORARY TABLE IF EXISTS ZonalPresetTargets;
CREATE TEMPORARY TABLE ZonalPresetTargets (
    UserId INT NOT NULL,
    PRIMARY KEY (UserId)
);

INSERT INTO ZonalPresetTargets (UserId)
SELECT sr.UserId
FROM StaffRoles sr
JOIN UserPermissions up ON up.UserId = sr.UserId
WHERE sr.RoleKey = 'ZonalAdmin'
GROUP BY sr.UserId
HAVING COUNT(*) = 3
   AND SUM(up.PermissionKey IN (
           'players.approve_registrations', 'players.view_export', 'players.edit_details'
       )) = 3;

-- 2. Audit rows, then the change (together)
START TRANSACTION;

INSERT INTO AuditLog (ActorUserId, ActingAsUserId, Action, TargetType, TargetId, Details, IpAddress, CreatedDate)
SELECT 0, NULL, 'access_changed', 'user', t.UserId,
       'Zonal Admin preset reduced to approve registrations only', '', NOW()
FROM ZonalPresetTargets t;

DELETE up
FROM UserPermissions up
JOIN ZonalPresetTargets t ON t.UserId = up.UserId
WHERE up.PermissionKey IN ('players.view_export', 'players.edit_details');

COMMIT;

SELECT COUNT(*) AS ZonalAdminsChanged FROM ZonalPresetTargets;

DROP TEMPORARY TABLE IF EXISTS ZonalPresetTargets;


-- 4. Zonal Admins who also organise: the Zonal Admin label and EXACTLY the
--    old three plus tournaments.organise_own and content.own_news_gallery.
--    They lose players.view_export and players.edit_details and keep the
--    other three. A second run changes nobody (they no longer have five).
DROP TEMPORARY TABLE IF EXISTS ZonalOrganiserTargets;
CREATE TEMPORARY TABLE ZonalOrganiserTargets (
    UserId INT NOT NULL,
    PRIMARY KEY (UserId)
);

INSERT INTO ZonalOrganiserTargets (UserId)
SELECT sr.UserId
FROM StaffRoles sr
JOIN UserPermissions up ON up.UserId = sr.UserId
WHERE sr.RoleKey = 'ZonalAdmin'
GROUP BY sr.UserId
HAVING COUNT(*) = 5
   AND SUM(up.PermissionKey IN (
           'players.approve_registrations', 'players.view_export', 'players.edit_details',
           'tournaments.organise_own', 'content.own_news_gallery'
       )) = 5;

START TRANSACTION;

INSERT INTO AuditLog (ActorUserId, ActingAsUserId, Action, TargetType, TargetId, Details, IpAddress, CreatedDate)
SELECT 0, NULL, 'access_changed', 'user', t.UserId,
       'Zonal Admin preset reduced to approve registrations only; organiser permissions kept', '', NOW()
FROM ZonalOrganiserTargets t;

DELETE up
FROM UserPermissions up
JOIN ZonalOrganiserTargets t ON t.UserId = up.UserId
WHERE up.PermissionKey IN ('players.view_export', 'players.edit_details');

COMMIT;

SELECT COUNT(*) AS ZonalOrganisersChanged FROM ZonalOrganiserTargets;

DROP TEMPORARY TABLE IF EXISTS ZonalOrganiserTargets;


-- 5. Check by hand: Zonal Admins whose permissions are neither exactly
--    players.approve_registrations nor exactly that plus
--    tournaments.organise_own and content.own_news_gallery.
--    Good: no rows, or only people whose extra access is intended.
SELECT u.UserId,
       u.PlayerId,
       CONCAT(u.FirstName, ' ', u.LastName) AS Name,
       COALESCE(GROUP_CONCAT(up.PermissionKey ORDER BY up.PermissionKey SEPARATOR ', '), '(none)') AS Permissions
FROM StaffRoles sr
JOIN Users u ON u.UserId = sr.UserId
LEFT JOIN UserPermissions up ON up.UserId = sr.UserId
WHERE sr.RoleKey = 'ZonalAdmin'
GROUP BY u.UserId, u.PlayerId, u.FirstName, u.LastName
HAVING COALESCE(GROUP_CONCAT(up.PermissionKey ORDER BY up.PermissionKey SEPARATOR ','), '') NOT IN (
           'players.approve_registrations',
           'content.own_news_gallery,players.approve_registrations,tournaments.organise_own'
       )
ORDER BY u.LastName, u.FirstName;
