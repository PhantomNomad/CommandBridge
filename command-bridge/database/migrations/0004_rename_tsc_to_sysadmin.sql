-- TSC (Technical Services Coordinator) is renamed SysAdmin. ICS Canada does not list a TSC position, so SysAdmin is
-- the application administrator only, not a position in the incident's command structure.
--  * users.is_tsc_admin is renamed users.is_sysadmin (the flag that makes someone a SysAdmin).
--  * The TSC role becomes SYSADMIN and is switched off, so it can no longer be assigned. Past assignments keep
--    pointing at it for the record; current assignments of it are ended.
--  * TSC is removed from the position catalogue (command hierarchy) and its form permission rows are removed:
--    SysAdmins already have full access to every form through users.is_sysadmin.
--  * Unread notifications addressed to the TSC role go to the SysAdmins instead.
-- Same as database/schema.sql and database/seed.sql. Safe to run more than once.

ALTER TABLE users CHANGE COLUMN IF EXISTS is_tsc_admin is_sysadmin tinyint(1) NOT NULL DEFAULT 0;

-- End current assignments of the TSC position (they stay in history).
UPDATE incident_positions ip
  INNER JOIN roles r ON r.id = ip.role_id
SET ip.assigned_end = COALESCE(ip.assigned_end, NOW()), ip.deleted_at = NOW(), ip.updated_at = NOW()
WHERE r.role_code IN ('TSC', 'SYSADMIN') AND ip.deleted_at IS NULL;

UPDATE operational_period_position_assignments
SET position_code = 'SYSADMIN', position_title = 'SysAdmin'
WHERE position_code = 'TSC';

UPDATE roles
SET role_code = 'SYSADMIN', role_name = 'SysAdmin (application administrator, not an ICS position)', is_command_role = 0, is_active = 0
WHERE role_code = 'TSC';

DELETE FROM incident_position_catalog WHERE acronym = 'TSC';
UPDATE incident_position_catalog SET reports_to_acronym = 'IC' WHERE reports_to_acronym = 'TSC';

DELETE FROM form_permissions WHERE role_code = 'TSC';

UPDATE notifications SET role_code = 'SYSADMIN' WHERE role_code = 'TSC' AND is_read = 0;
