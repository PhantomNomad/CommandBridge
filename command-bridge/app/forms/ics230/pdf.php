<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/pdf/Ics230PdfRenderer.php';
require_once dirname(dirname(__DIR__)) . '/includes/pdf/pdf_request.php';

require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) {
    http_response_code(404);
    echo 'No active incident.';
    exit;
}

$permissions = get_current_user_form_permissions('ICS230', $incidentId);
if (empty($permissions['can_view'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$currentOperationalPeriod = current_operational_period($incidentId);
$operationalPeriodId = (int)($_GET['op_id'] ?? ($currentOperationalPeriod['id'] ?? 0));
$operationalPeriod = $operationalPeriodId > 0 ? get_operational_period_by_id($incidentId, $operationalPeriodId) : null;
if (!$operationalPeriod) {
    http_response_code(404);
    echo 'Operational period not found.';
    exit;
}

$formRecord = get_form_record($incidentId, 'ICS230', (int)$operationalPeriod['id']);
$formRecordId = (int)($formRecord['id'] ?? 0);
$preparer = resolve_form_preparer($incidentId, 'ICS230');
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;

$preparedName = trim((string)($signature['signer_name'] ?? ($preparer['name'] ?? '')));
$preparedPosition = trim((string)($signature['signer_position_title'] ?? ($preparer['position_title'] ?? '')));
$preparedAt = (string)($signature['signed_at'] ?? ($formRecord['updated_at'] ?? $formRecord['created_at'] ?? date('Y-m-d H:i:s')));

$stmt = db()->prepare("
    SELECT
        m.id,
        m.title,
        m.scheduled_at,
        m.location,
        m.status,
        m.chair_name,
        m.chair_position_title,
        m.purpose,
        m.attendees_summary,
        m.notes,
        COALESCE(mt.name, m.title) AS meeting_type_name
    FROM operational_period_meetings m
    LEFT JOIN meeting_types mt ON mt.id = m.meeting_type_id
    WHERE m.incident_id = ?
      AND m.operational_period_id = ?
      AND m.deleted_at IS NULL
    ORDER BY m.scheduled_at ASC, m.display_order ASC, m.id ASC
");
$stmt->execute([$incidentId, (int)$operationalPeriod['id']]);
$meetings = $stmt->fetchAll();

$mode = (string)($_GET['mode'] ?? 'full');
if (!in_array($mode, ['full', 'posting'], true)) {
    $mode = 'full';
}

try {
    $renderer = new Ics230PdfRenderer([
        'incident' => $incident,
        'operational_period' => $operationalPeriod,
        'meetings' => $meetings,
        'prepared_by_name' => $preparedName,
        'prepared_by_position' => $preparedPosition,
        'prepared_at' => $preparedAt,
        'mode' => $mode,
    ]);

    $filename = $mode === 'posting'
        ? 'ICS230_meeting_posting.pdf'
        : 'ICS230_full.pdf';

    $renderer->renderDocument();
    $renderer->Output($filename, ics_pdf_output_mode());
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    render_header('ICS 230 PDF');
    echo '<div class="card"><p>' . e($e->getMessage()) . '</p></div>';
    render_footer();
    exit;
}
