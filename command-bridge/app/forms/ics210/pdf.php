<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics210OfficialPdfFieldMapper.php';

$ctx = ics_pdf_context('ICS210');
$pdo = db();
$recordId = (int)$ctx['form_record']['id'];

$st = $pdo->prepare('SELECT * FROM form_210_headers WHERE form_record_id = ? LIMIT 1');
$st->execute([$recordId]);
$header = $st->fetch() ?: [];

$st = $pdo->prepare('SELECT * FROM form_210_status_rows WHERE form_record_id = ? ORDER BY display_order, id');
$st->execute([$recordId]);
$rows = $st->fetchAll();

try {
    ics_pdf_send_official_paged(
        'ICS210',
        Ics210OfficialPdfFieldMapper::pages($header, $ctx['incident'], $ctx['op'], $rows, $ctx['form_record']),
        'ICS210_Incident_' . $ctx['incident_id'] . '.pdf'
    );
} catch (Throwable $e) {
    error_log('ICS 210 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 210 PDF could not be generated. Please contact the system administrator.');
}
exit;
