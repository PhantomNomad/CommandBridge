<?php
declare(strict_types=1);
/* ICS 214 PDF for one person's activity log: pdf.php?record_id=N (or ?user_id=N / your own for the selected period). */
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/TcpdfLoader.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/includes/ics214_log.php';

require_login();
if (!command_bridge_require_tcpdf()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TCPDF is not installed or could not be loaded.";
    exit;
}

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$myId = (int)(current_user()['id'] ?? 0);
if ($incidentId <= 0) { http_response_code(400); echo 'No active incident.'; exit; }

function p214_dt(?string $v): string { return $v ? date('Y-m-d H:i', (int)strtotime($v)) : ''; }

$recordParam = (int)($_GET['record_id'] ?? 0);
if ($recordParam > 0) {
    $log = ics214_find_log_by_record($incidentId, $recordParam);
    if (!$log) { http_response_code(404); echo 'ICS 214 activity log not found.'; exit; }
    $ownerId = (int)$log['owner_user_id'];
    $opId = (int)$log['operational_period_id'];
} else {
    $ownerId = (int)($_GET['user_id'] ?? 0) ?: $myId;
    $opId = (int)(current_operational_period($incidentId)['id'] ?? 0);
    $log = ics214_find_log($incidentId, $opId, $ownerId);
}
if (!ics214_access($incidentId, $ownerId, $opId)['view']) { http_response_code(403); echo 'Forbidden'; exit; }

$op = $opId > 0 ? get_operational_period_by_id($incidentId, $opId) : null;
$opText = $op ? p214_dt((string)$op['op_start']) . ' to ' . p214_dt((string)$op['op_end']) : '';
$owner = ics214_person($incidentId, $ownerId);
$recordId = (int)($log['id'] ?? 0);
$rows = ics214_rows($incidentId, $op, $ownerId, $recordId);

$pdf = new TCPDF('P','mm','LETTER',true,'UTF-8',false);
$pdf->SetCreator('Command Bridge');
$pdf->SetTitle('ICS 214 Activity Log - ' . $owner['name']);
$pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
$pdf->SetMargins(10,10,10); $pdf->AddPage();
$pdf->SetFont('helvetica','B',14);
$pdf->Cell(0,8,'ICS 214 - Activity Log',1,1,'C');
$incidentName = (string)($log['header_incident_name'] ?? '') ?: (string)($incident['name'] ?? $incident['incident_name'] ?? '');
$status = strtolower((string)($log['status'] ?? ''));
$signed = in_array($status, ['submitted', 'approved'], true)
    ? trim((string)($log['submitted_by_name'] ?? '') . ' ' . p214_dt($log['submitted_at'] ?? null)) : '';
$top = [
 ['1. Incident Name', $incidentName, '2. Operational Period', $opText],
 ['3. Name', (string)(($log['personnel_name'] ?? '') ?: $owner['name']), '4. ICS Position', (string)(($log['ICS_position_title'] ?? '') ?: $owner['position'])],
 ['5. Home Agency', (string)($log['home_agency'] ?? ''), '6. Unit Name', (string)($log['unit_name'] ?? '')],
 ['7. Date/Time Prepared', p214_dt($log['date_time_prepared'] ?? null), '8. Signed', $signed],
];
foreach ($top as $r) {
    $pdf->SetFont('helvetica','B',8); $pdf->Cell(38,8,$r[0],1); $pdf->SetFont('helvetica','',8); $pdf->Cell(57,8,$r[1],1);
    $pdf->SetFont('helvetica','B',8); $pdf->Cell(38,8,$r[2],1); $pdf->SetFont('helvetica','',8); $pdf->Cell(57,8,$r[3],1,1);
}
$pdf->Ln(3);
$pdf->SetFont('helvetica','B',9); $pdf->Cell(0,7,'Activity Log',1,1,'C');
$pdf->SetFont('helvetica','B',8); $pdf->Cell(32,7,'Date/Time',1); $pdf->Cell(123,7,'Notable Activities',1); $pdf->Cell(35,7,'Source',1,1);
$pdf->SetFont('helvetica','',8);
if (!$rows) { $pdf->Cell(190,7,'Nothing recorded for this operational period.',1,1); }
foreach ($rows as $r) {
    $src = $r['manual'] ? ('Entry' . ($r['by'] !== '' ? ' by ' . $r['by'] : '')) : (string)$r['source'];
    $hgt = max(7, $pdf->getStringHeight(123, (string)$r['text']) + 2, $pdf->getStringHeight(35, $src) + 2);
    if ($pdf->GetY() + $hgt > 265) { $pdf->AddPage(); }
    $pdf->MultiCell(32,$hgt,(string)$r['time'],1,'L',false,0);
    $pdf->MultiCell(123,$hgt,(string)$r['text'],1,'L',false,0);
    $pdf->MultiCell(35,$hgt,$src,1,'L',false,1);
}
$safe = preg_replace('/[^A-Za-z0-9]+/', '_', $owner['name']) ?: 'person';
$pdf->Output('ICS214_Activity_Log_' . $safe . '.pdf', ics_pdf_output_mode());
