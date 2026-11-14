<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';
require_once __DIR__ . '/Ics218OfficialPdfOverlay.php';

$ctx = ics_pdf_context('ICS218', false);
$pdo = db();
$formRecordId = get_or_create_form_record($ctx['incident_id'], 'ICS218', '218', (int)(current_user()['id'] ?? 0));
$stmt = $pdo->prepare('SELECT * FROM form_218_headers WHERE form_record_id = ? LIMIT 1');
$stmt->execute([$formRecordId]);
$header = $stmt->fetch() ?: [];
$entries = [];
if (!empty($header['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM form_218_entries WHERE header_id = ? AND deleted_at IS NULL ORDER BY id');
    $stmt->execute([(int)$header['id']]);
    $entries = $stmt->fetchAll();
}

try {
    // The official ICS 218 has no fillable fields: the text is drawn on an overlay and stamped onto the form.
    $exporter = new OfficialPdfTemplateExporter();
    $tempDir = getenv('COMMAND_BRIDGE_PDF_TEMP_DIR') ?: sys_get_temp_dir();
    [$overlay, $pages] = Ics218OfficialPdfOverlay::write($ctx['incident'], $header, $entries, $tempDir);
    $path = $exporter->stampTemplate('ICS218', $overlay, $pages);
    $filename = 'ICS218_Support_Vehicle_Inventory_Incident_' . $ctx['incident_id'] . '.pdf';
    if (ics_pdf_preview_requested()) {
        $exporter->sendInline($path, $filename);
    } else {
        $exporter->sendDownload($path, $filename);
    }
} catch (Throwable $e) {
    error_log('ICS 218 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 218 PDF could not be generated. Please contact the system administrator.');
}
exit;
