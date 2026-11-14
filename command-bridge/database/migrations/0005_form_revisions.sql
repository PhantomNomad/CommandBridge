-- Form revisions: a signed or approved form is locked; "Revise" reopens it, clears its signatures and records
-- the version that was replaced (who signed and approved it, why it was revised, and a PDF of it).
--  * form_records.revision counts the revisions (0 = original).
--  * form_revisions keeps one row per replaced version.
-- Same as database/schema.sql. Safe to run more than once.

ALTER TABLE form_records
  ADD COLUMN IF NOT EXISTS revision int(11) NOT NULL DEFAULT 0 AFTER reviewed_at;

CREATE TABLE IF NOT EXISTS form_revisions (
  id int(11) NOT NULL AUTO_INCREMENT,
  form_record_id int(11) NOT NULL,
  incident_id int(11) NOT NULL,
  form_code varchar(20) NOT NULL,
  revision int(11) NOT NULL,
  status_before varchar(20) NOT NULL,
  reason varchar(300) NOT NULL,
  revised_by_user_id int(11) DEFAULT NULL,
  revised_by_name varchar(255) DEFAULT NULL,
  revised_at datetime NOT NULL,
  prepared_by_name varchar(255) DEFAULT NULL,
  prepared_by_position_title varchar(150) DEFAULT NULL,
  prepared_signed_at datetime DEFAULT NULL,
  approved_by_name varchar(255) DEFAULT NULL,
  approved_by_position_title varchar(150) DEFAULT NULL,
  approved_signed_at datetime DEFAULT NULL,
  snapshot_file varchar(255) DEFAULT NULL,
  snapshot_error varchar(255) DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_form_revisions_record_rev (form_record_id, revision),
  KEY idx_form_revisions_incident (incident_id),
  CONSTRAINT fk_form_revisions_form FOREIGN KEY (form_record_id) REFERENCES form_records (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
