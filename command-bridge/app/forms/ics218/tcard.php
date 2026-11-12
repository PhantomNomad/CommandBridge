<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';

$rendererFile = $appRoot . '/includes/pdf/Ics219TCardPdfRenderer.php';
if (!is_file($rendererFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ICS 219 T-Card PDF renderer is missing.\n";
    echo "Expected file: " . $rendererFile . "\n";
    exit;
}
require_once $rendererFile;

require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) { http_response_code(400); echo 'No active incident.'; exit; }
if (!current_user_can_view_form('ICS218', $incidentId)) { http_response_code(403); echo 'Forbidden'; exit; }

$formRecordId = get_or_create_form_record($incidentId, 'ICS218', '218', (int)(current_user()['id'] ?? 0));
$stmt = $pdo->prepare("SELECT id FROM form_218_headers WHERE form_record_id=? LIMIT 1");
$stmt->execute([$formRecordId]);
$headerId = (int)($stmt->fetch()['id'] ?? 0);

$where = "header_id=? AND deleted_at IS NULL";
$params = [$headerId];
$entryId = (int)($_GET['entry_id'] ?? 0);
if ($entryId > 0) {
    $where .= " AND id=?";
    $params[] = $entryId;
}

$entries = [];
if ($headerId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM form_218_entries WHERE $where ORDER BY id");
    $stmt->execute($params);
    $entries = $stmt->fetchAll();
}

try {
    (new Ics219TCardPdfRenderer())->renderCards([
        'incident' => $incident,
        'entries' => $entries,
    ]);
} catch (Throwable $e) {
    error_log('ICS219 T-Card generation failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ICS 219 T-Card generation failed. Check the PHP error log for details.\n";
}
