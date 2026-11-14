<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics206OfficialPdfFieldMapper.php';

$ctx = ics_pdf_context('ICS206');
$pdo = db();
$st = $pdo->prepare('SELECT * FROM form_206_headers WHERE form_record_id = ? LIMIT 1');
$st->execute([(int)$ctx['form_record']['id']]);
$header = $st->fetch();
if (!$header) {
    ics_pdf_fail(404, 'ICS 206 has not been saved yet. Save the form, then try the PDF again.');
}
$hid = (int)$header['id'];
$rows = static function (string $table) use ($pdo, $hid): array {
    $st = $pdo->prepare("SELECT * FROM `$table` WHERE header_id = ? ORDER BY sort_order, id");
    $st->execute([$hid]);
    return $st->fetchAll();
};
$op = $ctx['op'];
if (!empty($header['operational_period_id'])) {
    $op = get_operational_period_by_id($ctx['incident_id'], (int)$header['operational_period_id']) ?: $op;
}

try {
    $built = Ics206OfficialPdfFieldMapper::build($header, $ctx['incident'], $op, $ctx['form_record'],
        $rows('form_206_aid_stations'), $rows('form_206_transportation'), $rows('form_206_hospitals'));
    ics_pdf_send_official_with_continuation('ICS206', $built['pages'], $built['continuations'],
        ics_pdf_header_lines($ctx['incident'], $op), 'ICS 206', 'ICS206_Medical_Plan_Incident_' . $ctx['incident_id'] . '.pdf');
} catch (Throwable $e) {
    error_log('ICS 206 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 206 PDF could not be generated. Please contact the system administrator.');
}
exit;
