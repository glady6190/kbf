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
-- 3. A list of Zonal Admins whose permissions are not exactly the new
--    preset, to check by hand on People, Staff and access.
--
-- A second run changes nobody: after the first run nobody has the old
-- three any more. Only UserPermissions rows are removed; nothing else is
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


-- 3. Check by hand: Zonal Admins whose permissions are not exactly
--    players.approve_registrations (none, or more, or different).
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
HAVING COALESCE(GROUP_CONCAT(up.PermissionKey ORDER BY up.PermissionKey SEPARATOR ','), '') <> 'players.approve_registrations'
ORDER BY u.LastName, u.FirstName;
