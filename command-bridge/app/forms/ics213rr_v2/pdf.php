<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';
require_once $appRoot . '/includes/pdf/SimpleTextPdf.php';
require_once __DIR__ . '/Ics213rrOfficialPdfFieldMapper.php';

require_login();
$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);   // form_213rr_headers.id
if ($incidentId <= 0) { ics_pdf_fail(400, 'No active incident.'); }
if ($id <= 0) { ics_pdf_fail(400, 'Open a resource request first, then use its PDF buttons.'); }
if (!current_user_can_ics213rr_action('view', $incidentId)) { ics_pdf_fail(403, 'Forbidden'); }

$st = $pdo->prepare("SELECT h.*, fr.incident_id, fr.prepared_by_name, fr.prepared_by_position_title, fr.created_at AS record_created_at
    FROM form_213rr_headers h INNER JOIN form_records fr ON fr.id = h.form_record_id
    WHERE h.id = ? AND fr.is_deleted = 0 LIMIT 1");
$st->execute([$id]);
$rr = $st->fetch();
if (!$rr || (int)$rr['incident_id'] !== $incidentId) { ics_pdf_fail(404, 'That resource request was not found in the active incident.'); }

$st = $pdo->prepare('SELECT * FROM form_213rr_items WHERE header_id = ? AND deleted_at IS NULL ORDER BY line_number, id');
$st->execute([$id]);
$items = $st->fetchAll();

// Who approved the request at the Section Chief step (first move into Logistics review).
$sectionChief = null;
try {
    $st = $pdo->prepare("SELECT changed_by_name, created_at FROM form_213rr_status_history WHERE header_id = ? AND to_status = 'logistics_review' ORDER BY id LIMIT 1");
    $st->execute([$id]);
    if ($h = $st->fetch()) { $sectionChief = ['name' => (string)$h['changed_by_name'], 'at' => (string)$h['created_at']]; }
} catch (Throwable $e) { $sectionChief = null; }

try {
    $built = Ics213rrOfficialPdfFieldMapper::build($rr, $items, $incident, $sectionChief);
    $exporter = new OfficialPdfTemplateExporter();
    $path = $exporter->create('ICS213RR', $built['fields']);
    if ($built['continuations']) {
        $extra = tempnam(sys_get_temp_dir(), 'cb213rr_');
        if ($extra === false || file_put_contents($extra, SimpleTextPdf::render('ICS 213RR - Continuation Sheet', $built['header'], $built['continuations'])) === false) {
            throw new RuntimeException('Unable to prepare the ICS 213RR continuation sheet.');
        }
        $path = $exporter->appendPdf($path, $extra);
    }
    $filename = 'ICS213RR_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$rr['request_number']) . '.pdf';
    if (ics_pdf_preview_requested()) { $exporter->sendInline($path, $filename); } else { $exporter->sendDownload($path, $filename); }
} catch (Throwable $e) {
    error_log('ICS 213RR official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 213RR PDF could not be generated. Please contact the system administrator.');
}
exit;
