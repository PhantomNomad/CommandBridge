<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/security.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_login();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Method not allowed.'); }
require_csrf_for_post();

$attachmentId = (int)($_POST['attachment_id'] ?? 0);
$attachment = $attachmentId > 0 ? get_ics201_attachment($attachmentId) : null;
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$user = current_user();
$userId = (int)($user['id'] ?? 0);

if (!$attachment) {
    flash('error', 'Attachment not found.');
    header('Location: ' . app_url('/forms/ics201/index.php'));
    exit;
}

if ($incidentId <= 0 || (int)($attachment['incident_id'] ?? 0) !== $incidentId) {
    flash('error', 'Attachment does not belong to the active incident.');
    header('Location: ' . app_url('/forms/ics201/index.php'));
    exit;
}

if (!current_user_can_edit_ics201($incidentId)) {
    flash('error', 'You do not have permission to remove ICS 201 attachments.');
    header('Location: ' . app_url('/forms/ics201/index.php'));
    exit;
}

$formRecord = get_form_record($incidentId, 'ICS201');
$formStatus = (string)($formRecord['status'] ?? 'draft');
if ($formStatus === 'submitted') {
    flash('error', 'ICS 201 is already submitted and attachments can no longer be removed.');
    header('Location: ' . app_url('/forms/ics201/index.php'));
    exit;
}

delete_ics201_attachment($attachmentId);
audit_log($userId, 'delete_attachment', 'ICS201', (int)$attachment['ics201_id'], 'Deleted ICS 201 attachment #' . $attachmentId);
flash('success', 'Attachment removed.');

header('Location: ' . app_url('/forms/ics201/index.php'));
exit;
