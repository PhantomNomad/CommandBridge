-- ICS 214 activity logs: one log per person per operational period.
--  * form_214_activity_log records who added each entry (entries are never edited or deleted).
--  * Existing logs are tied to the person who prepared them.
--  * Every signed-in user can open, add to and sign their OWN ICS 214 (other people's logs are limited in code to
--    IC/UC, SysAdmin, the person's supervisor, and the Documentation Unit Leader read-only).
-- Same as database/schema.sql and database/seed.sql. Safe to run more than once.

ALTER TABLE form_214_activity_log
  ADD COLUMN IF NOT EXISTS added_by_user_id int(11) DEFAULT NULL AFTER source_id,
  ADD COLUMN IF NOT EXISTS added_by_name varchar(255) DEFAULT NULL AFTER added_by_user_id;

ALTER TABLE form_214_activity_log
  ADD KEY IF NOT EXISTS idx_form_214_log_added_by (added_by_user_id);

UPDATE form_214_headers h
  INNER JOIN form_records fr ON fr.id = h.form_record_id
SET h.user_id = fr.prepared_by_user_id
WHERE h.user_id IS NULL AND fr.prepared_by_user_id IS NOT NULL;

INSERT INTO form_permissions
  (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, is_active)
VALUES
  ('ICS214', 'ALL_AUTHENTICATED', 1, 1, 1, 1, 0, 1)
ON DUPLICATE KEY UPDATE
  can_view = 1, can_create = 1, can_edit = 1, can_submit_final = 1, is_active = 1;
