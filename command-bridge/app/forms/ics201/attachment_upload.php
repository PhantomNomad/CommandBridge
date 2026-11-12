<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$user = current_user();
$userId = (int)($user['id'] ?? 0);

if ($incidentId <= 0) {
    flash('error', 'No active incident is available.');
    header('Location: ' . app_url('/forms/ics201/index.php'));
    exit;
}

if (!current_user_can_edit_ics201($incidentId)) {
    flash('error', 'You do not have permission to add ICS 201 attachments.');
    header('Location: ' . app_url('/forms/ics201/index.php'));
    exit;
}

$record = get_ics201_record($incidentId);
$form201Id = (int)($record['id'] ?? 0);
if ($form201Id <= 0) {
    flash('error', 'Save the ICS 201 draft before adding attachments.');
    header('Location: ' . app_url('/forms/ics201/index.php'));
    exit;
}

$formRecord = get_form_record($incidentId, 'ICS201');
$formStatus = (string)($formRecord['status'] ?? 'draft');
if ($formStatus === 'submitted') {
    flash('error', 'ICS 201 is already submitted and attachments can no longer be added.');
    header('Location: ' . app_url('/forms/ics201/index.php'));
    exit;
}

try {
    $savedUploads = save_ics201_uploads($form201Id, $incidentId, $_FILES['attachments'] ?? [], $userId);
    if (!$savedUploads) {
        throw new RuntimeException('No files were uploaded.');
    }
    audit_log($userId, 'upload_attachment', 'ICS201', $form201Id, 'Uploaded ' . count($savedUploads) . ' ICS 201 attachment(s).');
    flash('success', count($savedUploads) . ' attachment(s) uploaded.');
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}

header('Location: ' . app_url('/forms/ics201/index.php'));
exit;
