<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';
require_once $appRoot . '/includes/pdf/SimpleTextPdf.php';
require_once __DIR__ . '/Ics209OfficialPdfFieldMapper.php';
require_once __DIR__ . '/Ics209WfOfficialPdfFieldMapper.php';

// forms/ics209wf/pdf.php sets $s209PdfVariant = 'ICS209WF' and includes this file.
$s209PdfVariant = (isset($s209PdfVariant) && $s209PdfVariant === 'ICS209WF') ? 'ICS209WF' : 'ICS209';
$ctx = ics_pdf_context($s209PdfVariant, false);
$pdo = db();

// One ICS 209 per operational period. An older report can still be printed with &record_id=N.
$formRecord = $ctx['form_record'];
$requested = (int)($_GET['record_id'] ?? 0);
if ($requested > 0) {
    $st = $pdo->prepare("SELECT * FROM form_records WHERE id = ? AND incident_id = ? AND form_type = ? AND is_deleted = 0 LIMIT 1");
    $st->execute([$requested, $ctx['incident_id'], $s209PdfVariant]);
    $formRecord = $st->fetch() ?: [];
}
if (!$formRecord) {
    ics_pdf_fail(404, 'This report has not been saved yet for this operational period. Save the form, then try the PDF again.');
}

$st = $pdo->prepare('SELECT * FROM form_209 WHERE record_id = ? LIMIT 1');
$st->execute([(int)$formRecord['id']]);
$row = $st->fetch() ?: [];
if (!$row) {
    ics_pdf_fail(404, 'This report has not been saved yet for this operational period. Save the form, then try the PDF again.');
}

$positionCodes = [];
try {
    foreach ($pdo->query('SELECT id, role_code FROM roles')->fetchAll() as $r) { $positionCodes[(string)$r['id']] = (string)$r['role_code']; }
} catch (Throwable $e) {
    $positionCodes = [];
}

try {
    $resourceGrid = s209res_for_pdf($pdo, (int)$ctx['incident_id'], (int)$formRecord['id']);
    $exporter = new OfficialPdfTemplateExporter();
    if ($s209PdfVariant === 'ICS209WF') {
        // ICS 209-WF has its own fillable template (4 pages, 25-column resource table).
        $built = Ics209WfOfficialPdfFieldMapper::build($row, $ctx['incident'], $ctx['op'], $formRecord, $positionCodes, $resourceGrid);
        $path = $exporter->create('ICS209WF', $built['fields']);
    } else {
        $built = Ics209OfficialPdfFieldMapper::build($row, $ctx['incident'], $ctx['op'], $formRecord, $positionCodes, $resourceGrid, false);
        $path = $exporter->create('ICS209', $built['fields']);
    }

    if ($built['continuations']) {
        // Text that did not fit a box on the form is printed in full on a continuation sheet.
        $extra = tempnam(sys_get_temp_dir(), 'cb209_');
        if ($extra === false || file_put_contents($extra, SimpleTextPdf::render(($s209PdfVariant === 'ICS209WF' ? 'ICS 209-WF' : 'ICS 209') . ' - Continuation Sheet', $built['header'], $built['continuations'])) === false) {
            throw new RuntimeException('Unable to prepare the ICS 209 continuation sheet.');
        }
        $path = $exporter->appendPdf($path, $extra);
    }

    $filename = $s209PdfVariant . '_Report' . preg_replace('/[^0-9]/', '', (string)($row['report_number'] ?? '')) . '_Incident_' . $ctx['incident_id'] . '.pdf';
    if (ics_pdf_preview_requested()) {
        $exporter->sendInline($path, $filename);
    } else {
        $exporter->sendDownload($path, $filename);
    }
} catch (Throwable $e) {
    error_log('ICS 209 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 209 PDF could not be generated. Please contact the system administrator.');
}
exit;
