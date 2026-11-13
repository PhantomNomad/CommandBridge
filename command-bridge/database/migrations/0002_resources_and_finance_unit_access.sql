-- Let the Resources Unit and Finance/Admin open their pages.
-- forms/resources/index.php and forms/finance/index.php are controlled by form codes RESOURCES_UNIT and FINANCE_ADMIN,
-- but neither code was in `forms` and no role had permission, so only TSC administrators could open them (and the
-- Form Permissions page could not save permissions for them). Same rows as database/seed.sql.
-- Safe to run more than once, and after the same change was made by hand.
INSERT INTO forms (form_code, form_title, is_active, default_non_editor_mode, notes) VALUES
  ('RESOURCES_UNIT', 'Resources Unit', 1, 'hidden', 'Resources Unit intake board (not an ICS form). Controls who can open the Resources Unit page.'),
  ('FINANCE_ADMIN', 'Finance/Admin Queue', 1, 'hidden', 'Finance/Admin 213RR queue (not an ICS form). Controls who can open the Finance/Admin queue.')
ON DUPLICATE KEY UPDATE is_active = 1;

INSERT INTO form_permissions
  (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, is_active)
VALUES
  ('RESOURCES_UNIT', 'RESL', 1, 1, 1, 1, 0, 1),
  ('RESOURCES_UNIT', 'RESOURCE_UNIT', 1, 1, 1, 1, 0, 1),
  ('FINANCE_ADMIN', 'FSC', 1, 1, 1, 1, 0, 1),
  ('FINANCE_ADMIN', 'FINANCE_ADMIN', 1, 1, 1, 1, 0, 1)
ON DUPLICATE KEY UPDATE
  can_view = 1, can_create = 1, can_edit = 1, can_submit_final = 1, is_active = 1;
