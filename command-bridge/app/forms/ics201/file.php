<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_login();

$attachmentId = (int)($_GET['id'] ?? 0);
$attachment = $attachmentId > 0 ? get_ics201_attachment($attachmentId) : null;

if (!$attachment) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$incidentId = (int)($attachment['incident_id'] ?? 0);
$user = current_user();
$allowed = current_user_can_view_form('ICS201', $incidentId);

if (!$allowed) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$path = ics201_attachment_absolute_path($attachment);
if (!is_file($path)) {
    http_response_code(404);
    echo 'Stored file not found.';
    exit;
}

header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string)$attachment['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
