<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/Ics206PdfRenderer.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_login();

$pdo=db(); $incident=active_incident(); $incidentId=(int)($incident['id']??0);
if($incidentId<=0){http_response_code(400);echo 'No active incident.';exit;}
if(!current_user_can_view_form('ICS206',$incidentId)){http_response_code(403);echo 'Forbidden';exit;}
$formRecord=get_form_record($incidentId,'ICS206'); if(!$formRecord){http_response_code(404);echo 'ICS 206 record not found.';exit;}
$st=$pdo->prepare("SELECT * FROM form_206_headers WHERE form_record_id=? LIMIT 1"); $st->execute([(int)$formRecord['id']]); $header=$st->fetch();
if(!$header){http_response_code(404);echo 'ICS 206 record not found.';exit;}
$hid=(int)$header['id'];
$aid=$pdo->prepare("SELECT * FROM form_206_aid_stations WHERE header_id=? ORDER BY sort_order,id"); $aid->execute([$hid]); $aid=$aid->fetchAll();
$tr=$pdo->prepare("SELECT * FROM form_206_transportation WHERE header_id=? ORDER BY sort_order,id"); $tr->execute([$hid]); $tr=$tr->fetchAll();
$hos=$pdo->prepare("SELECT * FROM form_206_hospitals WHERE header_id=? ORDER BY sort_order,id"); $hos->execute([$hid]); $hos=$hos->fetchAll();
$op=[]; if(!empty($header['operational_period_id'])){ foreach(['operational_periods','incident_operational_periods'] as $t){ try{$s=$pdo->prepare("SELECT * FROM `$t` WHERE id=? LIMIT 1");$s->execute([(int)$header['operational_period_id']]);$op=$s->fetch()?:[]; if($op)break;}catch(Throwable $e){} } }
$renderer=new Ics206PdfRenderer(); $renderer->renderFilled(['incident'=>$incident,'operational_period'=>$op,'header'=>$header,'aid_stations'=>$aid,'transportation'=>$tr,'hospitals'=>$hos,'inline'=>ics_pdf_preview_requested()]);
