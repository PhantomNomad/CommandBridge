-- Dashboard panels per position (app/includes/dashboard_panels.php, admin/dashboard_settings.php).
-- Stores the default panel choices in app_settings.dashboard_panel_map. A map an administrator has already saved is
-- kept. Same as database/seed.sql. Safe to run more than once.

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('dashboard_panel_map', '{"_ALL":["action_items","my_forms","my_214","meetings"],"IC":["op_form_status","closeout","staffing","copied_review"],"UC":["op_form_status","closeout","staffing","copied_review"],"DIC":["op_form_status","closeout","staffing","copied_review"],"PSC":["op_form_status","closeout","staffing","copied_review"],"SITL":["op_form_status","closeout","copied_review"],"DOCL":["op_form_status","closeout","copied_review"],"RESL":["resl_queue","staffing"],"OSC":["staffing"],"LSC":["logistics_queue"],"SPUL":["logistics_queue"],"FSC":["finance_queue"],"PROC":["finance_queue"],"COST":["finance_queue"]}')
ON DUPLICATE KEY UPDATE setting_value = IF(COALESCE(setting_value, '') = '', VALUES(setting_value), setting_value);
