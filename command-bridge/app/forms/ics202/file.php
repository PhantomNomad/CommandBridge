<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_login();
$pdo=db(); $incident=active_incident(); $incidentId=(int)($incident['id']??0);
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS202', $incidentId) : get_form_permission_defaults('ICS202');
if (empty($permissions['can_view'])) { http_response_code(403); exit('Forbidden'); }
$id=(int)($_GET['id'] ?? 0);
$st=$pdo->prepare("SELECT a.*, fr.incident_id FROM form_202_attachments a INNER JOIN form_202_headers h ON h.id=a.form_202_header_id INNER JOIN form_records fr ON fr.id=h.form_record_id WHERE a.id=? LIMIT 1");
$st->execute([$id]); $a=$st->fetch();
if (!$a || (int)$a['incident_id'] !== $incidentId) { http_response_code(404); exit('Not found'); }
$path=storage_root_path().'/uploads/incidents/'.$incidentId.'/ics202/'.$a['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('File missing'); }
header('Content-Type: '.$a['mime_type']);
header('Content-Length: '.filesize($path));
header('Content-Disposition: inline; filename="'.str_replace('"','', $a['original_name']).'"');
readfile($path);
