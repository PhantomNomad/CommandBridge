<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/TcpdfLoader.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_login();
if (!command_bridge_require_tcpdf()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo "TCPDF is not installed or could not be loaded."; exit; }
$pdo=db(); $incident=active_incident(); $incidentId=(int)($incident['id'] ?? 0);
if($incidentId<=0){http_response_code(400);echo 'No active incident.';exit;}
if(!current_user_can_view_form('ICS215A',$incidentId)){http_response_code(403);echo 'Forbidden';exit;}
function p215a_dt(?string $v): string { return $v ? date('Y-m-d H:i', strtotime($v)) : ''; }
$formRecord=get_form_record($incidentId,'ICS215A'); if(!$formRecord){http_response_code(404);echo 'ICS 215A record not found.';exit;}
$formRecordId=(int)$formRecord['id'];
$st=$pdo->prepare("SELECT * FROM form_215a_headers WHERE form_record_id=? LIMIT 1"); $st->execute([$formRecordId]); $h=$st->fetch() ?: [];
$rows=[]; $st=$pdo->prepare("SELECT * FROM form_215a_rows WHERE form_record_id=? ORDER BY row_order,id"); $st->execute([$formRecordId]); $rows=$st->fetchAll();
$opText=''; try{$opId=(int)($h['operational_period_id'] ?? $formRecord['operational_period_id'] ?? 0); if($opId>0){$st=$pdo->prepare("SELECT * FROM operational_periods WHERE id=?");$st->execute([$opId]);$op=$st->fetch(); if($op)$opText=p215a_dt($op['op_start']).' to '.p215a_dt($op['op_end']);}}catch(Throwable $e){}
$pdf=new TCPDF('L','mm','LETTER',true,'UTF-8',false); $pdf->SetCreator('GovAI for Command Bridge'); $pdf->SetTitle('ICS 215A Safety Analysis'); $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); $pdf->SetMargins(10,10,10); $pdf->AddPage();
$pdf->SetFont('helvetica','B',14); $pdf->Cell(0,8,'ICS 215A - Incident Action Safety Plan Analysis',1,1,'C'); $pdf->SetFont('helvetica','',8);
$incidentName=(string)($h['incident_name'] ?? $incident['name'] ?? $incident['incident_name'] ?? '');
// Keep the top identification row inside the printable page width.
// Use wrapping cells because long incident names and operational periods can otherwise push past the right margin.
$dtPrepared = p215a_dt($h['date_time_prepared'] ?? '');
$usableW = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
$w1 = 30; $w2 = 63; $w3 = 34; $w4 = 40; $w5 = 36; $w6 = $usableW - ($w1 + $w2 + $w3 + $w4 + $w5);
$topH = max(
    9,
    $pdf->getStringHeight($w1, '1. Incident Name') + 3,
    $pdf->getStringHeight($w2, $incidentName) + 3,
    $pdf->getStringHeight($w3, '2. Date/Time Prepared') + 3,
    $pdf->getStringHeight($w4, $dtPrepared) + 3,
    $pdf->getStringHeight($w5, '3. Operational Period') + 3,
    $pdf->getStringHeight($w6, $opText) + 3
);
$pdf->SetFont('helvetica','B',8);
$pdf->MultiCell($w1,$topH,'1. Incident Name',1,'L',false,0);
$pdf->SetFont('helvetica','',8);
$pdf->MultiCell($w2,$topH,$incidentName,1,'L',false,0);
$pdf->SetFont('helvetica','B',8);
$pdf->MultiCell($w3,$topH,'2. Date/Time Prepared',1,'L',false,0);
$pdf->SetFont('helvetica','',8);
$pdf->MultiCell($w4,$topH,$dtPrepared,1,'L',false,0);
$pdf->SetFont('helvetica','B',8);
$pdf->MultiCell($w5,$topH,'3. Operational Period',1,'L',false,0);
$pdf->SetFont('helvetica','',8);
$pdf->MultiCell($w6,$topH,$opText,1,'L',false,1);
$pdf->Ln(3); $pdf->SetFont('helvetica','B',8); $pdf->Cell(65,7,'4. Incident Area',1); $pdf->Cell(90,7,'5. Hazards / Risks',1); $pdf->Cell(100,7,'6. Mitigations',1,1); $pdf->SetFont('helvetica','',8);
if(!$rows)$rows=[['incident_area'=>'','hazards_risks'=>'','mitigations'=>'']];
foreach($rows as $r){ $texts=[(string)($r['incident_area']??''),(string)($r['hazards_risks']??''),(string)($r['mitigations']??'')]; $hgt=max(12,$pdf->getStringHeight(65,$texts[0])+3,$pdf->getStringHeight(90,$texts[1])+3,$pdf->getStringHeight(100,$texts[2])+3); if($pdf->GetY()+$hgt>190){$pdf->AddPage();} $pdf->MultiCell(65,$hgt,$texts[0],1,'L',false,0); $pdf->MultiCell(90,$hgt,$texts[1],1,'L',false,0); $pdf->MultiCell(100,$hgt,$texts[2],1,'L',false,1); }
$pdf->Ln(4); $pdf->SetFont('helvetica','B',9); $pdf->Cell(0,7,'7. Prepared / Reviewed By',1,1,'C'); $pdf->SetFont('helvetica','B',8);
$pdf->Cell(75,8,'Safety Officer Name',1);$pdf->Cell(50,8,'Date/Time',1);$pdf->Cell(80,8,'Operations Section Chief Name',1);$pdf->Cell(50,8,'Date/Time',1,1);
$pdf->SetFont('helvetica','',8);$pdf->Cell(75,10,(string)($h['sofr_name']??''),1);$pdf->Cell(50,10,p215a_dt($h['sofr_signed_at']??''),1);$pdf->Cell(80,10,(string)($h['osc_name']??''),1);$pdf->Cell(50,10,p215a_dt($h['osc_signed_at']??''),1,1);
$pdf->Output('ICS215A_Safety_Analysis.pdf', ics_pdf_output_mode());
