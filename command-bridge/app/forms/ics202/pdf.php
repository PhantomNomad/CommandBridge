<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';

require_once $appRoot . '/includes/pdf/pdf_request.php';
// Only used to turn uploaded attachments into pages that follow the official form.
require_once $appRoot . '/includes/pdf/Ics202PdfRenderer.php';

require_login();

$pdo = db();

$requestedIncidentId = isset($_GET['incident_id']) && ctype_digit((string)$_GET['incident_id'])
    ? (int)$_GET['incident_id']
    : 0;
$requestedOpId = isset($_GET['op_id']) && ctype_digit((string)$_GET['op_id'])
    ? (int)$_GET['op_id']
    : 0;

$preview = isset($_GET['preview']) && (string)$_GET['preview'] === '1';

if ($requestedIncidentId > 0) {
    $incidentStmt = $pdo->prepare("
        SELECT id, incident_name AS name, incident_name, incident_number, incident_type, status, started_at, ended_at
        FROM incidents
        WHERE id = ? AND deleted_at IS NULL
        LIMIT 1
    ");
    $incidentStmt->execute([$requestedIncidentId]);
    $incident = $incidentStmt->fetch() ?: null;
} else {
    $incident = active_incident();
}

$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) {
    http_response_code(404);
    echo 'Incident not found.';
    exit;
}
if (!current_user_can_view_form('ICS202', $incidentId)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

/*
 * ICS 202 stores the selected operational period in form_202_headers.
 * Older records may not have the matching operational_period_id copied into
 * form_records, so locate the form through its header rather than relying on
 * the generic form_records operational-period lookup.
 */
$resolvedOpId = $requestedOpId;
if ($resolvedOpId <= 0) {
    $currentOp = current_operational_period($incidentId);
    $resolvedOpId = (int)($currentOp['id'] ?? 0);
}

$formRecord = null;
try {
    $headerRecordSql = "
        SELECT fr.*
        FROM form_records fr
        INNER JOIN form_202_headers h ON h.form_record_id = fr.id
        WHERE fr.incident_id = ?
          AND (
              REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '') = 'ICS202'
              OR CONCAT('ICS', REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '')) = 'ICS202'
          )
          AND fr.is_deleted = 0
    ";
    $headerRecordParams = [$incidentId];

    if ($resolvedOpId > 0) {
        $headerRecordSql .= " AND h.operational_period_id = ?";
        $headerRecordParams[] = $resolvedOpId;
    }

    $headerRecordSql .= " ORDER BY fr.updated_at DESC, fr.id DESC LIMIT 1";
    $headerRecordStmt = $pdo->prepare($headerRecordSql);
    $headerRecordStmt->execute($headerRecordParams);
    $formRecord = $headerRecordStmt->fetch() ?: null;

    /*
     * Old links can have an empty op_id. If the active operational period has
     * no saved ICS 202, use the newest saved ICS 202 for the incident.
     * A specifically supplied op_id is always honoured.
     */
    if (!$formRecord && $requestedOpId <= 0) {
        $latestHeaderStmt = $pdo->prepare("
            SELECT fr.*
            FROM form_records fr
            INNER JOIN form_202_headers h ON h.form_record_id = fr.id
            WHERE fr.incident_id = ?
              AND (
                  REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '') = 'ICS202'
                  OR CONCAT('ICS', REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '')) = 'ICS202'
              )
              AND fr.is_deleted = 0
            ORDER BY fr.updated_at DESC, fr.id DESC
            LIMIT 1
        ");
        $latestHeaderStmt->execute([$incidentId]);
        $formRecord = $latestHeaderStmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    /* Preserve the generic lookup as a safe fallback on older deployments. */
    $formRecord = get_form_record($incidentId, 'ICS202', $resolvedOpId > 0 ? $resolvedOpId : null);
}

if (!$formRecord) {
    http_response_code(404);
    echo 'No saved ICS 202 record was found for this incident. Save the ICS 202 form, then try the PDF export again.';
    exit;
}
$formRecordId = (int)$formRecord['id'];

function ics202_table_exists(PDO $pdo, string $table): bool {
    try { $st = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($st && $st->fetchColumn()); } catch (Throwable $e) { return false; }
}
function ics202_table_cols(PDO $pdo, string $table): array {
    try { return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field'); } catch (Throwable $e) { return []; }
}
function ics202_pick_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) { if (in_array($c, $cols, true)) return $c; }
    return null;
}

function ics202_form_table_has_data_for_op(PDO $pdo, int $incidentId, ?int $opId, string $formType, string $table, ?string $opColumn = 'operational_period_id'): bool {
    if ($incidentId <= 0 || !ics202_table_exists($pdo, $table) || !ics202_table_exists($pdo, 'form_records')) return false;
    try {
        $cols = ics202_table_cols($pdo, $table);
        if (!in_array('form_record_id', $cols, true)) return false;
        $sql = "SELECT COUNT(*) FROM `$table` h INNER JOIN form_records fr ON fr.id = h.form_record_id WHERE fr.incident_id = ? AND fr.form_type = ?";
        $params = [$incidentId, $formType];
        if ($opId && $opColumn && in_array($opColumn, $cols, true)) {
            $sql .= " AND h.`$opColumn` = ?";
            $params[] = $opId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) { return false; }
}

function ics202_has_assigned_incident_commander(PDO $pdo, int $incidentId): bool {
    if ($incidentId <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM incident_positions ip INNER JOIN roles r ON r.id=ip.role_id INNER JOIN users u ON u.id=ip.user_id AND u.deleted_at IS NULL WHERE ip.incident_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) AND r.role_code='IC'");
        $st->execute([$incidentId]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) { return false; }
}


$stmt = $pdo->prepare("SELECT * FROM form_202_headers WHERE form_record_id=? LIMIT 1");
$stmt->execute([$formRecordId]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    echo 'ICS 202 record not found.';
    exit;
}

$attachments = [];
if (!empty($record['id']) && ics202_table_exists($pdo, 'form_202_attachments')) {
    $st = $pdo->prepare("
        SELECT a.*, fr.incident_id
        FROM form_202_attachments a
        INNER JOIN form_202_headers h ON h.id = a.form_202_header_id
        INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE a.form_202_header_id=?
        ORDER BY a.created_at ASC, a.id ASC
    ");
    $st->execute([(int)$record['id']]);
    $attachments = $st->fetchAll();
}

$op = [];
$opId = (int)($record['operational_period_id'] ?? 0);
foreach (['operational_periods','incident_operational_periods'] as $opTable) {
    if ($opId <= 0 || !ics202_table_exists($pdo, $opTable)) {
        continue;
    }
    $cols = ics202_table_cols($pdo, $opTable);
    $id = ics202_pick_col($cols, ['id']);
    if (!$id) { continue; }
    $name = ics202_pick_col($cols, ['name','period_name','operational_period_name']);
    $start = ics202_pick_col($cols, ['start_time','start_datetime','period_start','from_datetime','op_start']);
    $end = ics202_pick_col($cols, ['end_time','end_datetime','period_end','to_datetime','op_end']);
    $select = "`$id` AS id".($name ? ", `$name` AS name" : '').($start ? ", `$start` AS start_time" : '').($end ? ", `$end` AS end_time" : '');
    $st = $pdo->prepare("SELECT $select FROM `$opTable` WHERE `$id`=? LIMIT 1");
    $st->execute([$opId]);
    $op = $st->fetch() ?: [];
    if ($op) { break; }
}


// Keep the PDF checklist aligned with what the ICS 202 page displays.
// The page auto-checks these when the related IAP form has any data for this incident/operational period.
$pdfAutoIncludes = [
    'iap_include_ics203' => ics202_has_assigned_incident_commander($pdo, $incidentId),
    'iap_include_ics204' => ics202_form_table_has_data_for_op($pdo, $incidentId, $opId ?: null, 'ICS204', 'form_204_headers'),
    'iap_include_ics205' => ics202_form_table_has_data_for_op($pdo, $incidentId, $opId ?: null, 'ICS205', 'form_205_headers'),
    'iap_include_ics205a' => (
        ics202_form_table_has_data_for_op($pdo, $incidentId, $opId ?: null, 'ICS205A', 'form_205a_headers')
        || ics202_form_table_has_data_for_op($pdo, $incidentId, null, 'ICS205A', 'form_205a_headers', null)
    ),
    'iap_include_ics206' => ics202_form_table_has_data_for_op($pdo, $incidentId, $opId ?: null, 'ICS206', 'form_206_headers'),
    'iap_include_ics208' => ics202_form_table_has_data_for_op($pdo, $incidentId, $opId ?: null, 'ICS208', 'form_208_headers'),
];
foreach ($pdfAutoIncludes as $field => $detected) {
    if ($detected) {
        $record[$field] = 1;
    }
}


/*
 * The ICS 202 is always the official ICS Canada form. Uploaded attachments follow it as pages of their own.
 */
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';
require_once __DIR__ . '/Ics202OfficialPdfFieldMapper.php';
$attachPdf = '';
try {
    $officialExporter = new OfficialPdfTemplateExporter();
    $officialPdfPath = $officialExporter->create('ICS202', Ics202OfficialPdfFieldMapper::map($record, $incident, $op));
    if ($attachments) {
        $layout = require $appRoot . '/includes/pdf/ics202_layout.php';
        $attachPdf = (string)tempnam(sys_get_temp_dir(), 'cb202att_');
        if ($attachPdf !== '' && (new Ics202PdfRenderer($layout))->writeAttachmentsPdf($attachments, $attachPdf)) {
            $officialPdfPath = $officialExporter->appendPdf($officialPdfPath, $attachPdf);
        } else {
            @unlink($attachPdf);
        }
        $attachPdf = '';
    }
    $officialFilename = 'ICS202_Incident_' . $incidentId . '.pdf';
    if ($preview) {
        $officialExporter->sendInline($officialPdfPath, $officialFilename);
    } else {
        $officialExporter->sendDownload($officialPdfPath, $officialFilename);
    }
    exit;
} catch (Throwable $e) {
    if ($attachPdf !== '') { @unlink($attachPdf); }
    error_log('ICS 202 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 202 PDF could not be generated. Please contact the system administrator.');
}
