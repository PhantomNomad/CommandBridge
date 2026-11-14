<?php
declare(strict_types=1);
/* ICS 214 PDF for one person's activity log: pdf.php?record_id=N (or ?user_id=N / your own for the selected period). */
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/includes/ics214_log.php';
require_once __DIR__ . '/Ics214OfficialPdfFieldMapper.php';

require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$myId = (int)(current_user()['id'] ?? 0);
if ($incidentId <= 0) { http_response_code(400); echo 'No active incident.'; exit; }

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
$owner = ics214_person($incidentId, $ownerId);
$recordId = (int)($log['id'] ?? 0);
$rows = ics214_rows($incidentId, $op, $ownerId, $recordId);

try {
    $built = Ics214OfficialPdfFieldMapper::build($log ?: [], $owner, $incident, $op ?: [], $rows);
    $safe = preg_replace('/[^A-Za-z0-9]+/', '_', (string)$owner['name']) ?: 'person';
    ics_pdf_send_official_with_continuation('ICS214', $built['pages'], [], [], 'ICS 214',
        'ICS214_Activity_Log_' . $safe . '.pdf', $built['keep']);
} catch (Throwable $e) {
    error_log('ICS 214 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 214 PDF could not be generated. Please contact the system administrator.');
}
exit;
