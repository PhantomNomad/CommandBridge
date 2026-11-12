<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics211OfficialPdfFieldMapper.php';

// The ICS 211 page lists every check-in for the incident, so the PDF does too (not limited to one operational period).
$ctx = ics_pdf_context('ICS211', false);
$pdo = db();

$st = $pdo->prepare("
    SELECT h.*
    FROM form_211_headers h
    INNER JOIN form_records fr ON fr.id = h.form_record_id
    WHERE fr.incident_id = ? AND fr.form_type = 'ICS211' AND fr.is_deleted = 0
    LIMIT 1
");
$st->execute([$ctx['incident_id']]);
$header = $st->fetch() ?: [];

$st = $pdo->prepare("
    SELECT e.*
    FROM form_211_entries e
    INNER JOIN form_211_headers h ON h.id = e.header_id
    INNER JOIN form_records fr ON fr.id = h.form_record_id
    WHERE e.deleted_at IS NULL
      AND fr.incident_id = ? AND fr.form_type = 'ICS211' AND fr.is_deleted = 0
    ORDER BY COALESCE(e.arrival_date, e.checkin_time, e.created_at), e.id
");
$st->execute([$ctx['incident_id']]);
$entries = $st->fetchAll();

try {
    ics_pdf_send_official_paged(
        'ICS211',
        Ics211OfficialPdfFieldMapper::pages($header, $ctx['incident'], $entries, $ctx['form_record']),
        'ICS211_Incident_' . $ctx['incident_id'] . '.pdf'
    );
} catch (Throwable $e) {
    error_log('ICS 211 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 211 PDF could not be generated. Please contact the system administrator.');
}
exit;
