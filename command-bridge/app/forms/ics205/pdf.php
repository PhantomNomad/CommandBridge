<?php
declare(strict_types=1);

$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics205OfficialPdfFieldMapper.php';

require_login();

$pdo = db();

$requestedIncidentId = isset($_GET['incident_id']) && ctype_digit((string) $_GET['incident_id'])
    ? (int) $_GET['incident_id']
    : 0;
$requestedOpId = isset($_GET['op_id']) && ctype_digit((string) $_GET['op_id'])
    ? (int) $_GET['op_id']
    : 0;

if ($requestedIncidentId > 0) {
    $incidentStmt = $pdo->prepare("
        SELECT *
        FROM incidents
        WHERE id = ?
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $incidentStmt->execute([$requestedIncidentId]);
    $incident = $incidentStmt->fetch() ?: null;
} else {
    $incident = active_incident();
}

$incidentId = (int) ($incident['id'] ?? 0);
if ($incidentId <= 0) {
    http_response_code(404);
    echo 'Incident not found.';
    exit;
}

if (!current_user_can_view_form('ICS205', $incidentId)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$resolvedOpId = $requestedOpId;
if ($resolvedOpId <= 0) {
    $currentOp = current_operational_period($incidentId);
    $resolvedOpId = (int) ($currentOp['id'] ?? 0);
}

/*
 * Locate the ICS 205 through its own header table. This mirrors the ICS 202
 * approach and honours the operational period selected on the form page.
 */
$formRecord = null;
try {
    $sql = "
        SELECT fr.*
        FROM form_records fr
        INNER JOIN form_205_headers h ON h.form_record_id = fr.id
        WHERE fr.incident_id = ?
          AND (
              REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '') = 'ICS205'
              OR CONCAT('ICS', REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '')) = 'ICS205'
          )
          AND fr.is_deleted = 0
    ";
    $params = [$incidentId];

    if ($resolvedOpId > 0) {
        $sql .= " AND h.operational_period_id = ?";
        $params[] = $resolvedOpId;
    }

    $sql .= " ORDER BY fr.updated_at DESC, fr.id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $formRecord = $stmt->fetch() ?: null;

    if (!$formRecord && $requestedOpId <= 0) {
        $stmt = $pdo->prepare("
            SELECT fr.*
            FROM form_records fr
            INNER JOIN form_205_headers h ON h.form_record_id = fr.id
            WHERE fr.incident_id = ?
              AND (
                  REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '') = 'ICS205'
                  OR CONCAT('ICS', REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '')) = 'ICS205'
              )
              AND fr.is_deleted = 0
            ORDER BY fr.updated_at DESC, fr.id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId]);
        $formRecord = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $formRecord = get_form_record($incidentId, 'ICS205');
}

if (!$formRecord) {
    http_response_code(404);
    echo 'No saved ICS 205 record was found for this incident. Save the form, then try the PDF export again.';
    exit;
}

$formRecordId = (int) ($formRecord['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM form_205_headers WHERE form_record_id = ? LIMIT 1');
$stmt->execute([$formRecordId]);
$header = $stmt->fetch() ?: null;

if (!$header) {
    http_response_code(404);
    echo 'ICS 205 record not found.';
    exit;
}

$channels = [];
$stmt = $pdo->prepare('SELECT * FROM form_205_channels WHERE form_205_id = ? ORDER BY sort_order, id');
$stmt->execute([(int) $header['id']]);
$channels = $stmt->fetchAll();

$op = [];
$opId = (int) ($header['operational_period_id'] ?? $resolvedOpId);
if ($opId > 0) {
    foreach (['operational_periods', 'incident_operational_periods'] as $opTable) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM `$opTable` WHERE id = ? LIMIT 1");
            $stmt->execute([$opId]);
            $op = $stmt->fetch() ?: [];
            if ($op) {
                break;
            }
        } catch (Throwable $e) {
            // Try the alternate operational-period table for older deployments.
        }
    }
}

/*
 * Use the same official-template exporter pattern as ICS 202. There is no
 * legacy renderer fallback: a successful download is always the ICS Canada
 * fillable ICS 205 form.
 */
try {
    $exporter = new OfficialPdfTemplateExporter();
    $pdfPath = $exporter->create(
        'ICS205',
        Ics205OfficialPdfFieldMapper::map($header, $incident, $op, $channels, $formRecord)
    );
    if (ics_pdf_preview_requested()) {
        $exporter->sendInline($pdfPath, 'ICS205_Incident_' . $incidentId . '.pdf');
    } else {
        $exporter->sendDownload($pdfPath, 'ICS205_Incident_' . $incidentId . '.pdf');
    }
    exit;
} catch (Throwable $e) {
    error_log('ICS 205 official PDF export failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The official ICS 205 PDF could not be generated. Please contact the system administrator.';
    exit;
}
