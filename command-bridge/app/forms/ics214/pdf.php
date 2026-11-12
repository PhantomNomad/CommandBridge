<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/TcpdfLoader.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/includes/ics214_activity.php';

require_login();
if (!command_bridge_require_tcpdf()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TCPDF is not installed or could not be loaded.";
    exit;
}

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$user = current_user();
$userId = (int)($user['id'] ?? 0);
if ($incidentId <= 0) { http_response_code(400); echo 'No active incident.'; exit; }
if (!current_user_can_view_form('ICS214', $incidentId)) { http_response_code(403); echo 'Forbidden'; exit; }

function p214_dt(?string $v): string { return $v ? date('Y-m-d H:i', strtotime($v)) : ''; }
function p214_table_exists(PDO $pdo, string $table): bool { try { $s=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($s && $s->fetchColumn()); } catch (Throwable $e) { return false; } }
function p214_name(array $u): string { return trim((string)($u['real_name'] ?? $u['display_name'] ?? $u['username'] ?? '')); }

$formRecord = get_form_record($incidentId, 'ICS214');
if (!$formRecord) { http_response_code(404); echo 'ICS 214 record not found.'; exit; }
$formRecordId=(int)$formRecord['id'];
$st=$pdo->prepare("SELECT * FROM form_214_headers WHERE form_record_id=? LIMIT 1");
$st->execute([$formRecordId]);
$h=$st->fetch() ?: [];

$opText='';
try {
  $opId=(int)($h['operational_period_id'] ?? $formRecord['operational_period_id'] ?? 0);
  if ($opId>0) { $st=$pdo->prepare("SELECT * FROM operational_periods WHERE id=?"); $st->execute([$opId]); $op=$st->fetch(); if($op) $opText=p214_dt($op['op_start']).' to '.p214_dt($op['op_end']); }
} catch(Throwable $e){}

$rows=[];
if (p214_table_exists($pdo,'form_214_activity_log')) {
  $st=$pdo->prepare("SELECT * FROM form_214_activity_log WHERE form_record_id=? AND source_type='manual' ORDER BY row_order,id");
  $st->execute([$formRecordId]);
  foreach($st->fetchAll() as $r) $rows[]=[p214_dt($r['activity_time'] ?? ''),(string)$r['activity_text'],'Manual'];
}
try {
  if (p214_table_exists($pdo,'operational_period_meetings')) {
    $st=$pdo->prepare("SELECT scheduled_at,title FROM operational_period_meetings WHERE incident_id=? AND deleted_at IS NULL AND scheduled_at <= NOW() ORDER BY scheduled_at ASC");
    $st->execute([$incidentId]);
    foreach($st->fetchAll() as $r) $rows[]=[p214_dt($r['scheduled_at']),'Meeting held: '.(string)$r['title'],'Meeting'];
  }
} catch(Throwable $e){}
foreach (ics214_audit_rows($pdo, $incidentId, $op ?? null) as $ar) { $rows[]=[$ar['time'],$ar['text'],$ar['source']]; }
usort($rows, fn($a,$b)=>strcmp($a[0],$b[0]));

$pdf = new TCPDF('P','mm','LETTER',true,'UTF-8',false);
$pdf->SetCreator('GovAI for Command Bridge');
$pdf->SetTitle('ICS 214 Activity Log');
$pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
$pdf->SetMargins(10,10,10); $pdf->AddPage();
$pdf->SetFont('helvetica','B',14);
$pdf->Cell(0,8,'ICS 214 - Activity Log',1,1,'C');
$pdf->SetFont('helvetica','',9);
$incidentName=(string)($h['incident_name'] ?? $incident['name'] ?? $incident['incident_name'] ?? '');
$top=[
 ['1. Incident Name',$incidentName,'2. Operational Period',$opText],
 ['3. Name',(string)($h['personnel_name'] ?? p214_name($user)),'4. ICS Position',(string)($h['ICS_position_title'] ?? '')],
 ['5. Home Agency',(string)($h['home_agency'] ?? ''),'6. Unit Name',(string)($h['unit_name'] ?? '')],
 ['7. Date/Time Prepared',p214_dt($h['date_time_prepared'] ?? ''),'8. Prepared By',trim((string)($h['prepared_by_name'] ?? '').' '.(string)($h['prepared_by_position_title'] ?? ''))],
];
foreach($top as $r){ $pdf->SetFont('helvetica','B',8); $pdf->Cell(38,8,$r[0],1); $pdf->SetFont('helvetica','',8); $pdf->Cell(57,8,$r[1],1); $pdf->SetFont('helvetica','B',8); $pdf->Cell(38,8,$r[2],1); $pdf->SetFont('helvetica','',8); $pdf->Cell(57,8,$r[3],1,1); }
$pdf->Ln(3);
$pdf->SetFont('helvetica','B',9); $pdf->Cell(0,7,'Activity Log',1,1,'C');
$pdf->SetFont('helvetica','B',8); $pdf->Cell(35,7,'Date/Time',1); $pdf->Cell(130,7,'Notable Activities',1); $pdf->Cell(25,7,'Source',1,1);
$pdf->SetFont('helvetica','',8);
foreach($rows as $r){
  $hgt=max(7,$pdf->getStringHeight(130,$r[1])+2);
  if ($pdf->GetY()+$hgt>265) { $pdf->AddPage(); }
  $x=$pdf->GetX(); $y=$pdf->GetY();
  $pdf->MultiCell(35,$hgt,$r[0],1,'L',false,0);
  $pdf->MultiCell(130,$hgt,$r[1],1,'L',false,0);
  $pdf->MultiCell(25,$hgt,$r[2],1,'L',false,1);
}
$pdf->Output('ICS214_Activity_Log.pdf', ics_pdf_output_mode());
