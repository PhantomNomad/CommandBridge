<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics204OfficialPdfFieldMapper.php';

$ctx = ics_pdf_context('ICS204');
$pdo = db();
$st = $pdo->prepare('SELECT * FROM form_204_headers WHERE form_record_id = ? LIMIT 1');
$st->execute([(int)$ctx['form_record']['id']]);
$header = $st->fetch();
if (!$header) {
    ics_pdf_fail(404, 'ICS 204 has not been saved yet. Save the form, then try the PDF again.');
}
$st = $pdo->prepare('SELECT * FROM form_204_resources WHERE header_id = ? ORDER BY line_number, id');
$st->execute([(int)$header['id']]);
$resources = $st->fetchAll();
$op = $ctx['op'];
if (!empty($header['operational_period_id'])) {
    $op = get_operational_period_by_id($ctx['incident_id'], (int)$header['operational_period_id']) ?: $op;
}

try {
    $built = Ics204OfficialPdfFieldMapper::build($header, $ctx['incident'], $op, $resources);
    ics_pdf_send_official_with_continuation('ICS204', $built['pages'], $built['continuations'],
        ics_pdf_header_lines($ctx['incident'], $op), 'ICS 204', 'ICS204_Assignment_List_Incident_' . $ctx['incident_id'] . '.pdf');
} catch (Throwable $e) {
    error_log('ICS 204 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 204 PDF could not be generated. Please contact the system administrator.');
}
exit;
