-- Command Bridge Check-In Gateway database schema (baseline for 0.9.0-beta.1)
-- Applied once by install.sh into an EMPTY, separate gateway database. Structure only, no data.
-- Later changes ship as numbered files in database/migrations/.
SET NAMES utf8mb4;
CREATE TABLE gateway_locations (
 id bigint unsigned NOT NULL AUTO_INCREMENT, internal_location_id bigint unsigned NOT NULL, incident_id bigint unsigned NOT NULL,
 location_name varchar(160) NOT NULL, location_type varchar(80) NOT NULL, street_address varchar(255) NOT NULL DEFAULT '',
 location_token char(32) NOT NULL, access_code_hash varchar(255) NOT NULL, lifecycle_status enum('draft','active','closed','archived') NOT NULL DEFAULT 'draft',
 created_at datetime NOT NULL DEFAULT current_timestamp(), updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY(id), UNIQUE KEY uq_gateway_location_internal(internal_location_id), UNIQUE KEY uq_gateway_location_token(location_token), KEY idx_gateway_location_active(incident_id,lifecycle_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_expected_resources (
 id bigint unsigned NOT NULL AUTO_INCREMENT, internal_expected_resource_id bigint unsigned NOT NULL, gateway_location_id bigint unsigned NOT NULL,
 incident_id bigint unsigned NOT NULL, resource_unit_intake_id bigint unsigned NOT NULL, reference_number varchar(100) NOT NULL DEFAULT '', provider_name varchar(160) NOT NULL DEFAULT '',
 provider_confirmed_at datetime DEFAULT NULL, publication_status enum('draft','published','withdrawn','checked_in','demobilized') NOT NULL DEFAULT 'draft',
 published_at datetime DEFAULT NULL, withdrawn_at datetime DEFAULT NULL, resource_category varchar(120) NOT NULL DEFAULT '', resource_kind varchar(120) NOT NULL DEFAULT '',
 resource_type varchar(160) NOT NULL DEFAULT '', resource_description varchar(500) NOT NULL DEFAULT '', quantity varchar(50) NOT NULL DEFAULT '',
 created_at datetime NOT NULL DEFAULT current_timestamp(), updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY(id), UNIQUE KEY uq_gateway_expected_internal(internal_expected_resource_id), KEY idx_gateway_expected_manifest(gateway_location_id,publication_status), CONSTRAINT fk_gateway_expected_location FOREIGN KEY(gateway_location_id) REFERENCES gateway_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_sync_receipts (
 id bigint unsigned NOT NULL AUTO_INCREMENT, message_id char(64) NOT NULL, request_nonce varchar(128) NOT NULL, event_type varchar(80) NOT NULL,
 payload_hash char(64) NOT NULL, received_at datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY(id), UNIQUE KEY uq_gateway_receipt_message(message_id), UNIQUE KEY uq_gateway_receipt_nonce(request_nonce)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_outbound_events (
 id bigint unsigned NOT NULL AUTO_INCREMENT, message_id varchar(128) NOT NULL, event_type varchar(80) NOT NULL, incident_id bigint unsigned NOT NULL,
 location_id bigint unsigned NOT NULL, event_payload longtext NOT NULL, delivery_status enum('pending','processing','delivered','failed') NOT NULL DEFAULT 'pending',
 attempt_count int unsigned NOT NULL DEFAULT 0, max_attempts int unsigned NOT NULL DEFAULT 12, next_attempt_at datetime DEFAULT NULL,
 locked_at datetime DEFAULT NULL, delivered_at datetime DEFAULT NULL, failed_at datetime DEFAULT NULL, last_http_status smallint unsigned DEFAULT NULL, last_error varchar(1000) DEFAULT NULL,
 created_at datetime NOT NULL DEFAULT current_timestamp(), updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY(id), UNIQUE KEY uq_gateway_outbound_message(message_id), KEY idx_gateway_outbound_delivery(delivery_status,next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_access_attempts (
 id bigint unsigned NOT NULL AUTO_INCREMENT, location_id bigint unsigned NOT NULL, remote_ip varchar(45) NOT NULL, attempted_pin_fingerprint char(64) DEFAULT NULL, attempted_at datetime NOT NULL DEFAULT current_timestamp(),
 PRIMARY KEY(id), KEY idx_gateway_access_rate(location_id,remote_ip,attempted_at), KEY idx_gateway_access_pin_rate(location_id,attempted_pin_fingerprint,attempted_at), KEY idx_gateway_access_ip_time(remote_ip,attempted_at), KEY idx_gateway_access_location_time(location_id,attempted_at), CONSTRAINT fk_gateway_access_location FOREIGN KEY(location_id) REFERENCES gateway_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CIR/SAM identities are synchronized from CommandBridge. PINs are stored
-- only as supplied bcrypt verifiers; plaintext PINs are never retained.
CREATE TABLE gateway_companion_users (
 id bigint unsigned NOT NULL AUTO_INCREMENT, incident_id bigint unsigned NOT NULL, gateway_user_id bigint unsigned NOT NULL,
 display_name varchar(160) NOT NULL, roles_json text NOT NULL, pin_verifier varchar(255) DEFAULT NULL,
 active tinyint(1) NOT NULL DEFAULT 0, revoked_at datetime DEFAULT NULL, pin_last_reset_at datetime DEFAULT NULL,
 created_at datetime NOT NULL DEFAULT current_timestamp(), updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY(id), UNIQUE KEY uq_gateway_companion_user(incident_id,gateway_user_id),
 KEY idx_gateway_companion_user_access(incident_id,active,revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_companion_user_locations (
 id bigint unsigned NOT NULL AUTO_INCREMENT, gateway_companion_user_id bigint unsigned NOT NULL, gateway_location_id bigint unsigned NOT NULL,
 assigned_at datetime NOT NULL DEFAULT current_timestamp(), revoked_at datetime DEFAULT NULL, updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY(id), UNIQUE KEY uq_gateway_companion_user_location(gateway_companion_user_id,gateway_location_id),
 KEY idx_gateway_companion_location_access(gateway_location_id,revoked_at),
 CONSTRAINT fk_gateway_companion_location_user FOREIGN KEY(gateway_companion_user_id) REFERENCES gateway_companion_users(id),
 CONSTRAINT fk_gateway_companion_location_location FOREIGN KEY(gateway_location_id) REFERENCES gateway_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gateway_audit_log (
 id bigint unsigned NOT NULL AUTO_INCREMENT, location_id bigint unsigned DEFAULT NULL, gateway_user_id bigint unsigned DEFAULT NULL, action varchar(100) NOT NULL, actor_name varchar(160) DEFAULT NULL,
 actor_agency varchar(160) DEFAULT NULL, actor_position varchar(20) DEFAULT NULL, actor_role varchar(20) DEFAULT NULL, remote_ip varchar(45) DEFAULT NULL, details_json longtext DEFAULT NULL,
 created_at datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY(id), KEY idx_gateway_audit_location(location_id,created_at), KEY idx_gateway_audit_user(gateway_user_id,created_at), KEY idx_gateway_audit_action(action,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schema_migrations (
 version varchar(64) NOT NULL, applied_at datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY(version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
