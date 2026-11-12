<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics207OfficialPdfFieldMapper.php';

// Same source the ICS 207 page uses: the positions currently assigned on the incident.
// The form record is optional so the chart can be previewed before it has been submitted.
$ctx = ics_pdf_context('ICS207', false);
$pdo = db();

$st = $pdo->prepare("
    SELECT u.real_name, r.role_code AS code, r.role_name AS name
    FROM incident_positions ip
    INNER JOIN roles r ON r.id = ip.role_id
    LEFT JOIN users u ON u.id = ip.user_id
    WHERE ip.incident_id = ?
      AND ip.deleted_at IS NULL
      AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
    ORDER BY r.role_code, u.real_name
");
$st->execute([$ctx['incident_id']]);
$positions = $st->fetchAll();

// A submitted ICS 207 is a snapshot; print what was submitted rather than today's assignments.
if (($ctx['form_record']['status'] ?? '') === 'submitted' && !empty($ctx['form_record']['id'])) {
    $snap = $pdo->prepare('SELECT generated_json FROM form_207_snapshots WHERE form_record_id = ? ORDER BY id DESC LIMIT 1');
    $snap->execute([(int)$ctx['form_record']['id']]);
    $decoded = json_decode((string)($snap->fetchColumn() ?: ''), true);
    if (is_array($decoded) && $decoded) {
        $positions = $decoded;
    }
}

try {
    ics_pdf_send_official(
        'ICS207',
        Ics207OfficialPdfFieldMapper::map($positions, $ctx['incident'], $ctx['op'], $ctx['form_record']),
        'ICS207_Incident_' . $ctx['incident_id'] . '.pdf'
    );
} catch (Throwable $e) {
    error_log('ICS 207 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 207 PDF could not be generated. Please contact the system administrator.');
}
exit;
