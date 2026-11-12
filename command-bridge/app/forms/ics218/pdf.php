<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';

$rendererFile = $appRoot . '/includes/pdf/Ics218PdfRenderer.php';
if (!is_file($rendererFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ICS 218 PDF renderer is missing.\n";
    echo "Expected file: " . $rendererFile . "\n";
    exit;
}
require_once $rendererFile;
require_once $appRoot . '/includes/pdf/pdf_request.php';

require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) { http_response_code(400); echo 'No active incident.'; exit; }
if (!current_user_can_view_form('ICS218', $incidentId)) { http_response_code(403); echo 'Forbidden'; exit; }

$formRecordId = get_or_create_form_record($incidentId, 'ICS218', '218', (int)(current_user()['id'] ?? 0));
$stmt = $pdo->prepare("SELECT * FROM form_218_headers WHERE form_record_id=? LIMIT 1");
$stmt->execute([$formRecordId]);
$header = $stmt->fetch() ?: [];

$entries = [];
if (!empty($header['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM form_218_entries WHERE header_id=? AND deleted_at IS NULL ORDER BY id");
    $stmt->execute([(int)$header['id']]);
    $entries = $stmt->fetchAll();
}

try {
    (new Ics218PdfRenderer())->renderInventory([
        'incident' => $incident,
        'header' => $header,
        'entries' => $entries,
        'inline' => ics_pdf_preview_requested(),
    ]);
} catch (Throwable $e) {
    error_log('ICS218 PDF generation failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ICS 218 PDF generation failed. Check the PHP error log for details.\n";
}
