<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/Ics208PdfRenderer.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_login();

$pdo=db(); $incident=active_incident(); $incidentId=(int)($incident['id']??0);
if($incidentId<=0){http_response_code(400);echo 'No active incident.';exit;}
if(!current_user_can_view_form('ICS208',$incidentId)){http_response_code(403);echo 'Forbidden';exit;}
$formRecord=get_form_record($incidentId,'ICS208'); if(!$formRecord){http_response_code(404);echo 'ICS 208 record not found.';exit;}
$st=$pdo->prepare("SELECT * FROM form_208_headers WHERE form_record_id=? LIMIT 1"); $st->execute([(int)$formRecord['id']]); $header=$st->fetch();
if(!$header){http_response_code(404);echo 'ICS 208 record not found.';exit;}
$op=[]; if(!empty($header['operational_period_id'])){ foreach(['operational_periods','incident_operational_periods'] as $t){ try{$s=$pdo->prepare("SELECT * FROM `$t` WHERE id=? LIMIT 1");$s->execute([(int)$header['operational_period_id']]);$op=$s->fetch()?:[]; if($op)break;}catch(Throwable $e){} } }
$renderer=new Ics208PdfRenderer(); $renderer->renderFilled(['incident'=>$incident,'operational_period'=>$op,'header'=>$header,'record'=>$formRecord,'inline'=>ics_pdf_preview_requested()]);
