<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics213OfficialPdfFieldMapper.php';

/*
 * ICS 213 General Message. A form record holds many messages, so the PDF is for one message:
 *   pdf.php?message_id=N   the requested message (must belong to the active incident)
 *   pdf.php                the most recent message for the operational period
 * Add &preview=1 to show it in the browser instead of downloading it.
 */
$ctx = ics_pdf_context('ICS213');
$pdo = db();
$messageId = (int)($_GET['message_id'] ?? 0);

if ($messageId > 0) {
    $st = $pdo->prepare("SELECT m.* FROM form_213_messages m
        INNER JOIN form_records fr ON fr.id = m.form_record_id
        WHERE m.id = ? AND fr.incident_id = ? AND fr.is_deleted = 0 LIMIT 1");
    $st->execute([$messageId, $ctx['incident_id']]);
} else {
    $st = $pdo->prepare("SELECT * FROM form_213_messages WHERE form_record_id = ? ORDER BY date_time DESC, id DESC LIMIT 1");
    $st->execute([(int)$ctx['form_record']['id']]);
}
$message = $st->fetch();
if (!$message) {
    ics_pdf_fail(404, 'No ICS 213 message has been saved yet. Save a message, then try the PDF again.');
}

try {
    ics_pdf_send_official(
        'ICS213',
        Ics213OfficialPdfFieldMapper::map($message, $ctx['incident']),
        'ICS213_Message_' . (int)$message['id'] . '.pdf'
    );
} catch (Throwable $e) {
    error_log('ICS 213 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 213 PDF could not be generated. Please contact the system administrator.');
}
exit;
