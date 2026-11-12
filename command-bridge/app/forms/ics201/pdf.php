<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/pdf/Ics201PdfRenderer.php';
require_once dirname(dirname(__DIR__)) . '/includes/pdf/pdf_request.php';

require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) {
    http_response_code(400);
    echo 'No active incident.';
    exit;
}

if (!current_user_can_view_form('ICS201', $incidentId)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$formRecord = get_form_record($incidentId, 'ICS201');
$record = get_ics201_record($incidentId);

if (!$formRecord || !$record) {
    http_response_code(404);
    echo 'ICS 201 record not found.';
    exit;
}

$form201Id = (int)($record['id'] ?? 0);
$attachments = $form201Id > 0 ? get_ics201_attachments($form201Id) : [];
$currentActions = $form201Id > 0 ? get_ics201_actions($form201Id, 'current') : [];
$plannedActions = $form201Id > 0 ? get_ics201_actions($form201Id, 'planned') : [];
$resources = $form201Id > 0 ? get_ics201_resources($form201Id) : [];
$preparerName = trim((string)($record['prepared_by_name'] ?? ''));
$preparerPosition = trim((string)($record['prepared_by_position'] ?? ''));
if ($preparerName === '' || $preparerPosition === '') {
    $preparer = resolve_form_preparer($incidentId, 'ICS201');
    if ($preparerName === '') {
        $preparerName = trim((string)($preparer['name'] ?? ''));
    }
    if ($preparerPosition === '') {
        $preparerPosition = trim((string)($preparer['position_title'] ?? ''));
    }
}
$preparedBy = trim($preparerName . ($preparerPosition !== '' ? ' - ' . $preparerPosition : ''));

$signature = ((int)($formRecord['id'] ?? 0) > 0) ? get_form_signature((int)$formRecord['id']) : null;
$formStatus = (string)($formRecord['status'] ?? 'draft');
$signedByName = trim((string)($signature['signer_name'] ?? ''));
$signatureName = ($formStatus === 'submitted' && $signedByName !== '') ? $signedByName : trim((string)($record['signature_name'] ?? ''));

try {
    $layout = require dirname(dirname(__DIR__)) . '/includes/pdf/ics201_layout.php';
    $renderer = new Ics201PdfRenderer($layout);
    $renderer->renderFilled([
        'incident' => $incident,
        'record' => $record,
        'attachments' => $attachments,
        'current_actions' => $currentActions,
        'planned_actions' => $plannedActions,
        'resources' => $resources,
        'prepared_by' => $preparedBy,
        'signature_name' => $signatureName,
        'inline' => ics_pdf_preview_requested(),
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    render_header('ICS 201 PDF');
    echo '<div class="card"><p>' . e($e->getMessage()) . '</p></div>';
    render_footer();
    exit;
}
