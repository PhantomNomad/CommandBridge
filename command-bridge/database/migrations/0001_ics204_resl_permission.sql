-- Give the Resources Unit Leader (RESL) the ICS 204 permissions the on-screen help already describes.
-- Safe to run more than once, and to run after the same change was made by hand.
INSERT INTO form_permissions
  (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, is_active)
VALUES
  ('ICS204', 'RESL', 1, 1, 1, 1, 0, 1)
ON DUPLICATE KEY UPDATE
  can_view = 1, can_create = 1, can_edit = 1, can_submit_final = 1, is_active = 1;
