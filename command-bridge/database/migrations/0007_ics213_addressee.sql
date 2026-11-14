-- ICS 213 General Message: who it is for, so the addressee gets a dashboard notification, and who sent it, so the
-- sender is told when it is answered. Same as database/schema.sql. Safe to run more than once.

ALTER TABLE form_213_messages
  ADD COLUMN IF NOT EXISTS to_role_code varchar(50) DEFAULT NULL AFTER to_name,
  ADD COLUMN IF NOT EXISTS from_user_id int(11) DEFAULT NULL AFTER from_name;
