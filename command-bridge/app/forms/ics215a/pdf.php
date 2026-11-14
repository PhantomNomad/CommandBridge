<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics215AOfficialPdfFieldMapper.php';

$ctx = ics_pdf_context('ICS215A');
$pdo = db();
$formRecordId = (int)$ctx['form_record']['id'];
$st = $pdo->prepare('SELECT * FROM form_215a_headers WHERE form_record_id = ? LIMIT 1');
$st->execute([$formRecordId]);
$header = $st->fetch() ?: [];
$st = $pdo->prepare('SELECT * FROM form_215a_rows WHERE form_record_id = ? ORDER BY row_order, id');
$st->execute([$formRecordId]);
$rows = $st->fetchAll();
$op = $ctx['op'];
$opId = (int)($header['operational_period_id'] ?? $ctx['form_record']['operational_period_id'] ?? 0);
if ($opId > 0) {
    $op = get_operational_period_by_id($ctx['incident_id'], $opId) ?: $op;
}

try {
    $built = Ics215AOfficialPdfFieldMapper::build($header, $ctx['incident'], $op, $rows);
    ics_pdf_send_official_with_continuation('ICS215A', $built['pages'], $built['continuations'],
        ics_pdf_header_lines($ctx['incident'], $op), 'ICS 215A', 'ICS215A_Safety_Analysis_Incident_' . $ctx['incident_id'] . '.pdf');
} catch (Throwable $e) {
    error_log('ICS 215A official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 215A PDF could not be generated. Please contact the system administrator.');
}
exit;
