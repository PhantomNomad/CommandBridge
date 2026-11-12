<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics205AOfficialPdfFieldMapper.php';

$ctx = ics_pdf_context('ICS205A');
$pdo = db();

$st = $pdo->prepare('SELECT * FROM form_205a_headers WHERE form_record_id = ? LIMIT 1');
$st->execute([(int)$ctx['form_record']['id']]);
$header = $st->fetch();
if (!$header) {
    ics_pdf_fail(404, 'ICS 205A has not been saved yet. Save the form, then try the PDF again.');
}

$st = $pdo->prepare('SELECT * FROM form_205a_entries WHERE header_id = ? AND deleted_at IS NULL ORDER BY display_order, id');
$st->execute([(int)$header['id']]);
$entries = $st->fetchAll();

try {
    ics_pdf_send_official_paged(
        'ICS205A',
        Ics205AOfficialPdfFieldMapper::pages($header, $ctx['incident'], $ctx['op'], $entries, $ctx['form_record']),
        'ICS205A_Incident_' . $ctx['incident_id'] . '.pdf'
    );
} catch (Throwable $e) {
    error_log('ICS 205A official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 205A PDF could not be generated. Please contact the system administrator.');
}
exit;
