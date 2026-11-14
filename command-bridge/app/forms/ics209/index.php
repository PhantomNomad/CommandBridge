<?php
declare(strict_types=1);
/**
 * ICS 209 - Incident Status Summary
 *
 * One record per operational period (the report number counts up across the whole incident).
 * Opening the page loads (or creates) the record for the selected operational period and pre-fills any
 * blank field from, in this order:
 *   1. the previous ICS 209 report for the incident (carry-forward),
 *   2. the incident record, the assigned IC/UC, organization settings and the operational period.
 * Values that were saved on this report always win. Narrative fields get "Insert from ..." buttons that
 * offer text from the ICS 201 / 202 / 208 / 213RR / 215A instead of silently overwriting what the user typed.
 */
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/form_action_buttons.php';
require_login();

// forms/ics209wf/index.php sets $s209Variant = 'ICS209WF' and includes this file; both forms share this engine and the form_209 table.
$s209Variant = (isset($s209Variant) && $s209Variant === 'ICS209WF') ? 'ICS209WF' : 'ICS209';
define('S209_FORM', $s209Variant);
define('S209_WF', $s209Variant === 'ICS209WF');
define('S209_NUMBER', S209_WF ? '209WF' : '209');
define('S209_TITLE', S209_WF ? 'ICS 209-WF - Wildfire Incident Status Summary' : 'ICS 209 - Incident Status Summary');
define('S209_URL', '/forms/' . strtolower(S209_FORM) . '/index.php');
require_once __DIR__ . '/../../includes/ics209_resources.php';

$pdo = db();
$incidentId = (int)($_POST['incident_id'] ?? $_GET['incident_id'] ?? 0);
if ($incidentId <= 0) { $incidentId = (int)(active_incident_id() ?: ($_SESSION['current_incident_id'] ?? 0)); }
if ($incidentId > 0) { require_active_operational_period($incidentId); }

// ---------------------------------------------------------------------------------------------------------------------
// Field catalogue (every stored column of form_209)
// ---------------------------------------------------------------------------------------------------------------------
$horizons = ['12h' => '0-12 hours', '24h' => '12-24 hours', '48h' => '24-48 hours', '72h' => '48-72 hours', 'after72h' => '> 72 hours'];
$statusRows = ['fatalities' => 'Fatalities', 'injuries_illness' => 'Injuries / Illness', 'trapped_rescue' => 'Trapped / In Need of Rescue',
    'missing' => 'Missing', 'evacuated' => 'Evacuated', 'sheltering_place' => 'Sheltering in Place', 'temporary_shelters' => 'In Temporary Shelters',
    'received_immunizations' => 'Have Received Immunizations', 'require_immunizations' => 'Require Immunizations', 'quarantine' => 'In Quarantine',
    'total_affected' => 'Total # Affected'];
$damageRows = S209_WF
    ? ['single_residences' => 'Single Residences', 'multiple_residences' => 'Multiple Residences', 'mixed_commercial_residential' => 'Mixed Commercial / Residential',
       'nonres_commercial' => 'Non-Residential Commercial Property', 'other_minor_structures' => 'Other Minor Structures']
    : ['single_residences' => 'Single Residences', 'nonres_commercial' => 'Non-Residential Commercial Property',
       'other_minor_structures' => 'Other Minor Structures', 'other' => 'Other'];
$threats = [
    'threat_no_likely_threat' => 'No likely threat', 'threat_potential_future_threat' => 'Potential future threat',
    'threat_mass_notifications_progress' => 'Mass notifications in progress', 'threat_mass_notifications_completed' => 'Mass notifications completed',
    'threat_no_evacuations_imminent' => 'No evacuations imminent', 'threat_planning_evacuation' => 'Planning for evacuation',
    'threat_planning_shelter_place' => 'Planning for shelter-in-place', 'threat_evacuations_progress' => 'Evacuation(s) in progress',
    'threat_shelter_place_progress' => 'Shelter-in-place in progress', 'threat_repopulation_progress' => 'Repopulation in progress',
    'threat_mass_immunization_progress' => 'Mass immunization in progress', 'threat_mass_immunization_complete' => 'Mass immunization complete',
    'threat_quarantine_progress' => 'Quarantine in progress', 'threat_area_restriction' => 'Area restriction in effect',
];

$textFields = ['incident_name', 'incident_no', 'report_version', 'report_number', 'incident_commanders', 'incident_management_org',
    'incident_start_date', 'incident_start_time', 'current_size_area', 'percent_contained', 'incident_definition', 'fire_status', 'complexity_level',
    'period_from', 'period_to', 'prepared_by_name', 'prepared_by_position_id', 'prepared_by_signature', 'prepared_datetime',
    'submitted_datetime', 'approved_by_name', 'approved_by_position_id', 'approved_by_signature', 'approved_datetime',
    'primary_location_sent_to', 'province_territory', 'county_regional', 'city', 'unit_other', 'incident_jurisdiction', 'location_ownership',
    'longitude', 'latitude', 'datum', 'legal_description', 'short_location_desc', 'utm_coordinates', 'geospatial_data',
    'significant_events', 'primary_materials_hazards', 'damage_assessment_info', 'life_safety_remarks', 'weather_concerns',
    'objectives', 'strategic_discussion', 'planned_actions', 'projected_final_size', 'anticipated_completion_date', 'demob_start_date',
    'estimated_costs_to_date', 'projected_final_cost', 'remarks'];
$allFields = $textFields;
foreach ($damageRows as $k => $_) { foreach (['threatened', 'damaged', 'destroyed'] as $c) { $allFields[] = "damage_{$k}_{$c}"; } }
foreach (['public', 'responder'] as $grp) { foreach ($statusRows as $k => $_) { foreach (['period', 'total'] as $c) { $allFields[] = "{$grp}_{$k}_{$c}"; } } }
foreach ($threats as $k => $_) { $allFields[] = $k; }
foreach (['projected_activity', 'threat_risk', 'critical_resource_needs'] as $grp) { foreach ($horizons as $k => $_) { $allFields[] = "{$grp}_{$k}"; } }

/** Fields that are NOT carried into the next report (they describe one operational period, or are sign-off data). */
$noCarry = ['report_number', 'report_version', 'period_from', 'period_to', 'prepared_by_name', 'prepared_by_position_id', 'prepared_by_signature',
    'prepared_datetime', 'submitted_datetime', 'approved_by_name', 'approved_by_position_id', 'approved_by_signature', 'approved_datetime',
    'significant_events', 'life_safety_remarks', 'weather_concerns', 'objectives', 'strategic_discussion', 'planned_actions', 'remarks'];
foreach ($horizons as $k => $_) { array_push($noCarry, "projected_activity_{$k}", "threat_risk_{$k}", "critical_resource_needs_{$k}"); }
foreach (['public', 'responder'] as $grp) { foreach ($statusRows as $k => $_) { $noCarry[] = "{$grp}_{$k}_period"; } }
$carryFields = array_values(array_diff($allFields, $noCarry));

// ---------------------------------------------------------------------------------------------------------------------
// Small helpers (prefixed so they cannot clash with other includes)
// ---------------------------------------------------------------------------------------------------------------------
function s209_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Run a query and return rows; any schema difference simply yields no suggestions / defaults. */
function s209_rows(PDO $pdo, string $sql, array $params = []): array
{
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}

function s209_next_report_number(PDO $pdo, int $incidentId, int $excludeRecordId): int
{
    $rows = s209_rows($pdo, "SELECT MAX(CAST(f.report_number AS UNSIGNED)) AS m
        FROM form_209 f INNER JOIN form_records fr ON fr.id = f.record_id
        WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND f.record_id <> ?", [$incidentId, $excludeRecordId]);
    return ((int)($rows[0]['m'] ?? 0)) + 1;
}

/** Most recent earlier ICS 209 for the incident (highest report number), excluding the current record. */
function s209_previous_report(PDO $pdo, int $incidentId, int $excludeRecordId): ?array
{
    $rows = s209_rows($pdo, "SELECT f.*, fr.operational_period_id AS _op_id
        FROM form_209 f INNER JOIN form_records fr ON fr.id = f.record_id
        WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND f.record_id <> ?
        ORDER BY CAST(f.report_number AS UNSIGNED) DESC, fr.id DESC LIMIT 1", [$incidentId, $excludeRecordId]);
    return $rows[0] ?? null;
}

/** Text offered by the "Insert from ..." buttons: [field => [[label, text], ...]]. */
function s209_suggestions(PDO $pdo, int $incidentId, int $opId): array
{
    $out = [];
    $add = function (string $field, string $label, string $text) use (&$out) {
        $text = trim($text);
        if ($text !== '') { $out[$field][] = ['label' => $label, 'text' => $text]; }
    };

    $h202 = s209_rows($pdo, "SELECT h.id, h.objectives, h.operational_period_command_emphasis, h.general_situational_awareness
        FROM form_202_headers h INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND fr.operational_period_id = ? ORDER BY h.id DESC LIMIT 1", [$incidentId, $opId]);
    if ($h202) {
        $add('objectives', 'ICS 202 objectives', (string)$h202[0]['objectives']);
        $add('strategic_discussion', 'ICS 202 situational awareness', (string)$h202[0]['general_situational_awareness']);
        $add('strategic_discussion', 'ICS 202 command emphasis', (string)$h202[0]['operational_period_command_emphasis']);
        $w = s209_rows($pdo, "SELECT weather_forecast FROM form_202_weather WHERE header_id = ? ORDER BY line_number, id", [(int)$h202[0]['id']]);
        $add('weather_concerns', 'ICS 202 weather forecast', implode("\n", array_filter(array_map(fn($r) => trim((string)$r['weather_forecast']), $w))));
    }

    $b201 = s209_rows($pdo, "SELECT situation_summary, safety_briefing, current_objectives, planned_objectives
        FROM form_201_incident_briefing WHERE incident_id = ? ORDER BY id DESC LIMIT 1", [$incidentId]);
    if ($b201) {
        $add('significant_events', 'ICS 201 situation summary', (string)$b201[0]['situation_summary']);
        $add('objectives', 'ICS 201 current objectives', (string)$b201[0]['current_objectives']);
        $add('planned_actions', 'ICS 201 planned objectives', (string)$b201[0]['planned_objectives']);
        $add('life_safety_remarks', 'ICS 201 safety briefing', (string)$b201[0]['safety_briefing']);
    }

    $m208 = s209_rows($pdo, "SELECT h.safety_message FROM form_208_headers h INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND fr.operational_period_id = ? ORDER BY h.id DESC LIMIT 1", [$incidentId, $opId]);
    if ($m208) { $add('life_safety_remarks', 'ICS 208 safety message', (string)$m208[0]['safety_message']); }

    $r215 = s209_rows($pdo, "SELECT r.incident_area, r.hazards_risks, r.mitigations
        FROM form_215a_rows r INNER JOIN form_records fr ON fr.id = r.form_record_id
        WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND fr.operational_period_id = ? ORDER BY r.row_order, r.id", [$incidentId, $opId]);
    if ($r215) {
        $haz = []; $risk = [];
        foreach ($r215 as $r) {
            $area = trim((string)$r['incident_area']); $hz = trim((string)$r['hazards_risks']); $mt = trim((string)$r['mitigations']);
            if ($hz !== '') { $haz[] = ($area !== '' ? $area . ': ' : '') . $hz; }
            if ($hz !== '' || $mt !== '') { $risk[] = ($area !== '' ? $area . ': ' : '') . $hz . ($mt !== '' ? ' (Mitigation: ' . $mt . ')' : ''); }
        }
        $add('primary_materials_hazards', 'ICS 215A hazards', implode("\n", $haz));
        $add('threat_risk_12h', 'ICS 215A hazards & mitigations', implode("\n", $risk));
    }

    $i213 = s209_rows($pdo, "SELECT i.item_description, i.quantity_requested, i.unit_of_issue, i.justification
        FROM form_213rr_items i INNER JOIN form_213rr_headers h ON h.id = i.header_id INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND fr.operational_period_id = ? AND i.deleted_at IS NULL
        ORDER BY h.id, i.line_number", [$incidentId, $opId]);
    if ($i213) {
        $lines = [];
        foreach ($i213 as $i) {
            $qty = rtrim(rtrim(number_format((float)$i['quantity_requested'], 2, '.', ''), '0'), '.');
            $lines[] = trim($qty . ' ' . (string)$i['unit_of_issue'] . ' ' . (string)$i['item_description']);
        }
        $add('critical_resource_needs_12h', 'ICS 213RR requests this period', implode("\n", $lines));
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------------
// Context, permissions and the one record for this operational period
// ---------------------------------------------------------------------------------------------------------------------
$error = null;
$permissions = $incidentId > 0 ? get_current_user_form_permissions(S209_FORM, $incidentId) : get_form_permission_defaults(S209_FORM);
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);

if ($incidentId <= 0) {
    render_header(S209_TITLE);
    echo '<div class="card"><h2>' . s209_h(S209_TITLE) . '</h2><p class="muted">Select an incident first.</p></div>';
    render_footer();
    exit;
}
if (!$canView) {
    http_response_code(403);
    render_header(S209_TITLE);
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

// A specific (legacy) record may still be opened with ?record_id=; it fixes the operational period.
$requestedRecordId = (int)($_GET['record_id'] ?? $_POST['record_id'] ?? 0);
$formRecord = null;
if ($requestedRecordId > 0) {
    $rows = s209_rows($pdo, "SELECT * FROM form_records WHERE id = ? AND incident_id = ? AND is_deleted = 0 LIMIT 1", [$requestedRecordId, $incidentId]);
    $formRecord = $rows[0] ?? null;
}
$opId = (int)($_POST['operational_period_id'] ?? $_GET['op_id'] ?? $_GET['operational_period_id'] ?? 0);
if ($formRecord && (int)$formRecord['operational_period_id'] > 0) { $opId = (int)$formRecord['operational_period_id']; }
if ($opId <= 0) { $opId = (int)(current_operational_period($incidentId)['id'] ?? 0); }
$opRow = $opId > 0 ? get_operational_period_by_id($incidentId, $opId) : null;
if (!$formRecord && $opId > 0) { $formRecord = get_form_record($incidentId, S209_FORM, $opId); }
$formRecordId = (int)($formRecord['id'] ?? 0);
$user = current_user();
$userId = (int)($user['id'] ?? 0);

$cols = table_columns('form_209');
if (!$cols) {
    render_header(S209_TITLE);
    echo '<div class="card"><h2>' . s209_h(S209_TITLE) . '</h2><div class="flash error">The form_209 table is missing. Run update.sh (see docs/UPDATING.md) to bring the database up to date.</div></div>';
    render_footer();
    exit;
}

$stored = [];
if ($formRecordId > 0) {
    $r = s209_rows($pdo, "SELECT * FROM form_209 WHERE record_id = ? LIMIT 1", [$formRecordId]);
    $stored = $r[0] ?? [];
}
$formStatus = strtolower((string)($formRecord['status'] ?? 'draft'));
$locked = in_array($formStatus, ['submitted', 'approved', 'complete', 'final'], true);
$preparer = resolve_form_preparer($incidentId, S209_FORM);

// ---------------------------------------------------------------------------------------------------------------------
// Save / submit
// ---------------------------------------------------------------------------------------------------------------------
// Signed or approved: locked until someone revises it (includes/form_lock.php).
require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
cb_form_block_locked_post(S209_FORM, (int)($incidentId ?? 0), (int)($opId ?? 0), (int)($formRecordId ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if (!$canEdit) { throw new RuntimeException('You do not have permission to edit this form.'); }
        if ($opId <= 0) { throw new RuntimeException('Select or create an operational period before saving ICS 209.'); }
        if ($locked) { throw new RuntimeException('This report has been signed and is locked. Return it for changes (Sign-off section below) to edit it.'); }

        $data = [];
        foreach ($allFields as $f) {
            if (strpos($f, 'threat_') === 0 && isset($threats[$f])) { $data[$f] = isset($_POST[$f]) ? '1' : '0'; continue; }
            $data[$f] = isset($_POST[$f]) && !is_array($_POST[$f]) ? trim((string)$_POST[$f]) : '';
        }

        $pdo->beginTransaction();
        $formRecordId = get_or_create_form_record($incidentId, S209_FORM, S209_NUMBER, $userId, $opId);
        apply_form_preparer_defaults($formRecordId, $preparer);

        // The report number is assigned once, incident-wide, and never taken from the browser.
        $existing = s209_rows($pdo, "SELECT report_number, report_version FROM form_209 WHERE record_id = ? LIMIT 1", [$formRecordId]);
        $number = (string)($existing[0]['report_number'] ?? '');
        if ($number === '' || (int)$number <= 0) {
            $number = (string)s209_next_report_number($pdo, $incidentId, $formRecordId);
        }
        $data['report_number'] = $number;
        if ($data['report_version'] === '') { $data['report_version'] = ((int)$number > 1) ? 'Update' : 'Initial'; }
        if ($data['prepared_by_name'] === '') { $data['prepared_by_name'] = (string)($preparer['name'] ?? ''); }
        if ($data['prepared_datetime'] === '') { $data['prepared_datetime'] = date('Y-m-d H:i'); }

        if ($action === 'submit') {
            $missing = [];
            $required = ['incident_name' => 'Incident Name', 'incident_commanders' => 'Incident Commander(s)', 'incident_start_date' => 'Incident Start Date',
                      'period_from' => 'Time Period From', 'period_to' => 'Time Period To'];
            $required += S209_WF ? ['fire_status' => 'Status (OC / BH / UC / O)'] : ['incident_definition' => 'Incident Definition'];
            foreach ($required as $k => $label) {
                if ($data[$k] === '') { $missing[] = $label; }
            }
            if ($missing) { throw new RuntimeException('Complete these required fields before submitting: ' . implode(', ', $missing) . '.'); }
            if ($data['submitted_datetime'] === '') { $data['submitted_datetime'] = date('Y-m-d H:i'); }
        }

        $write = array_values(array_filter($allFields, fn($f) => in_array($f, $cols, true)));
        $colList = array_merge(['record_id'], $write);
        $sql = 'INSERT INTO form_209 (' . implode(', ', array_map(fn($c) => "`$c`", $colList)) . ')'
             . ' VALUES (' . implode(', ', array_fill(0, count($colList), '?')) . ')'
             . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "`$c`=VALUES(`$c`)", $write));
        $pdo->prepare($sql)->execute(array_merge([$formRecordId], array_map(fn($f) => $data[$f], $write)));
        if (in_array('updated_at', $cols, true)) { $pdo->prepare('UPDATE form_209 SET updated_at = NOW() WHERE record_id = ?')->execute([$formRecordId]); }

        // Saving counts as the review of a copied-forward record.
        if (in_array('review_required', table_columns('form_records'), true)) {
            $pdo->prepare('UPDATE form_records SET review_required = 0, updated_at = NOW() WHERE id = ?')->execute([$formRecordId]);
        }

        // Blocks 48-53 (resource commitment summary): rebuilt from current resources, or saved as edited on the page.
        $liveGrid = s209res_collect($pdo, $incidentId);
        $liveHash = s209res_hash($liveGrid);
        $postedJson = (string)($_POST['res209_grid_json'] ?? '');
        $resWarning = '';
        try {
            if (!empty($_POST['refresh_resources'])) {
                s209res_save($pdo, $formRecordId, $liveGrid, 'auto', $liveHash);
            } elseif ($postedJson !== '') {
                $decoded = json_decode($postedJson, true);
                if (is_array($decoded)) {
                    $postedGrid = s209res_normalize($decoded);
                    s209res_save($pdo, $formRecordId, $postedGrid, s209res_hash($postedGrid) === $liveHash ? 'auto' : 'edited', $liveHash);
                }
            }
        } catch (Throwable $resEx) {
            // Never lose the rest of the report because the new table has not been created yet.
            $resWarning = 'The report was saved, but the resource summary (blocks 48-53) could not be: ' . $resEx->getMessage();
            security_log($resEx, 'ics209_resources_save');
        }

        if ($action === 'submit') { submit_form_record($formRecordId, S209_FORM, $incidentId, $userId); }
        $pdo->commit();

        if ($resWarning !== '') { flash('warning', $resWarning); }
        flash('success', !empty($_POST['refresh_resources']) ? 'Resource summary rebuilt from current check-ins and equipment. Report #' . $number . ' saved.'
            : ($action === 'submit' ? S209_TITLE . ' report #' . $number . ' submitted and digitally signed. Now name an approver in the Sign-off section.' : S209_TITLE . ' report #' . $number . ' saved.'));
        header('Location: ' . app_url(S209_URL) . '?' . http_build_query(['incident_id' => $incidentId, 'op_id' => $opId]));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
        // Keep what the user typed.
        $decodedKeep = json_decode((string)($_POST['res209_grid_json'] ?? ''), true);
        if (is_array($decodedKeep)) { $resGridOverride = s209res_normalize($decodedKeep); }
        foreach ($allFields as $f) {
            if (strpos($f, 'threat_') === 0 && isset($threats[$f])) { $stored[$f] = isset($_POST[$f]) ? '1' : '0'; }
            elseif (isset($_POST[$f]) && !is_array($_POST[$f])) { $stored[$f] = trim((string)$_POST[$f]); }
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Values shown on the page: saved value > previous report > incident / settings / operational period
// ---------------------------------------------------------------------------------------------------------------------
$values = $stored;
$source = [];          // field => short source note shown next to the label
$isBlank = function ($k) use (&$values) { return !isset($values[$k]) || trim((string)$values[$k]) === ''; };
$fillable = !$locked && $canEdit;

$prev = s209_previous_report($pdo, $incidentId, $formRecordId);
$prevNumber = $prev ? (int)$prev['report_number'] : 0;

if ($fillable) {
    if ($prev) {
        foreach ($carryFields as $f) {
            if (strpos($f, 'threat_') === 0 && isset($threats[$f])) { continue; }   // checkboxes handled below
            if ($isBlank($f) && isset($prev[$f]) && trim((string)$prev[$f]) !== '') {
                $values[$f] = $prev[$f];
                $source[$f] = 'carried from report #' . $prevNumber;
            }
        }
        // Threat checkboxes are carried only while this report has none ticked.
        $anyTicked = false;
        foreach ($threats as $k => $_) { if (!empty($values[$k])) { $anyTicked = true; break; } }
        if (!$anyTicked) {
            foreach ($threats as $k => $_) { if (!empty($prev[$k])) { $values[$k] = '1'; $source[$k] = 'carried'; } }
        }
    }

    // Incident, command, organization and operational period.
    $def = [];
    $inc = s209_rows($pdo, "SELECT incident_name, incident_number, incident_type, started_at FROM incidents WHERE id = ? AND deleted_at IS NULL", [$incidentId]);
    if ($inc) {
        $def['incident_name'] = [$inc[0]['incident_name'] ?? '', 'incident record'];
        $def['incident_no'] = [$inc[0]['incident_number'] ?? '', 'incident record'];
        $def['incident_definition'] = [$inc[0]['incident_type'] ?? '', 'incident type'];
        if (!empty($inc[0]['started_at'])) {
            $def['incident_start_date'] = [date('Y-m-d', strtotime((string)$inc[0]['started_at'])), 'incident record'];
            $def['incident_start_time'] = [date('H:i', strtotime((string)$inc[0]['started_at'])), 'incident record'];
        }
    }
    $cmd = s209_rows($pdo, "SELECT GROUP_CONCAT(DISTINCT CONCAT(COALESCE(u.real_name, u.username), ' - ', r.role_code) ORDER BY u.real_name SEPARATOR '\n') AS c
        FROM incident_positions ip JOIN roles r ON r.id = ip.role_id JOIN users u ON u.id = ip.user_id
        WHERE ip.incident_id = ? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) AND r.role_code IN ('IC','UC')", [$incidentId]);
    if (!empty($cmd[0]['c'])) { $def['incident_commanders'] = [$cmd[0]['c'], 'assigned IC/UC']; }
    $org = trim((string)(function_exists('get_app_setting') ? (get_app_setting('organization_name', '') ?? '') : ''));
    if ($org !== '') { $def['incident_management_org'] = [$org, 'organization setting']; }
    if ($opRow) {
        $def['period_from'] = [!empty($opRow['op_start']) ? date('Y-m-d H:i', strtotime((string)$opRow['op_start'])) : '', 'operational period'];
        $def['period_to'] = [!empty($opRow['op_end']) ? date('Y-m-d H:i', strtotime((string)$opRow['op_end'])) : '', 'operational period'];
    }
    foreach ($def as $f => [$v, $note]) {
        // The operational period always follows the period selected for this report while it is still blank.
        if ($isBlank($f) && trim((string)$v) !== '') { $values[$f] = $v; $source[$f] = $note; }
    }
    // Incident-record values beat a carried-forward copy of the same field.
    foreach (['incident_name', 'incident_no', 'incident_start_date', 'incident_start_time', 'incident_commanders', 'incident_management_org'] as $f) {
        if (isset($def[$f]) && ($source[$f] ?? '') === 'carried from report #' . $prevNumber && trim((string)$def[$f][0]) !== '') {
            $values[$f] = $def[$f][0]; $source[$f] = $def[$f][1];
        }
    }
    // The period always belongs to the selected operational period, never to the previous report.
    foreach (['period_from', 'period_to'] as $f) {
        if (isset($def[$f]) && $def[$f][0] !== '' && !isset($stored[$f])) { $values[$f] = $def[$f][0]; $source[$f] = $def[$f][1]; }
    }

    if ($isBlank('report_number')) {
        $values['report_number'] = (string)s209_next_report_number($pdo, $incidentId, $formRecordId);
        $source['report_number'] = 'next in incident sequence';
    }
    if ($isBlank('report_version')) { $values['report_version'] = ((int)$values['report_number'] > 1) ? 'Update' : 'Initial'; }
    if ($isBlank('prepared_by_name') && !empty($preparer['name']) && $preparer['name'] !== 'TBD') { $values['prepared_by_name'] = $preparer['name']; $source['prepared_by_name'] = 'assigned preparer'; }
    if ($isBlank('prepared_by_position_id') && !empty($preparer['role_code'])) {
        $rid = s209_rows($pdo, "SELECT id FROM roles WHERE role_code = ? LIMIT 1", [$preparer['role_code']]);
        if ($rid) { $values['prepared_by_position_id'] = (string)$rid[0]['id']; $source['prepared_by_position_id'] = 'assigned preparer'; }
    }
    if ($isBlank('prepared_datetime')) { $values['prepared_datetime'] = date('Y-m-d H:i'); }
}
if ($isBlank('report_number')) { $values['report_number'] = (string)($stored['report_number'] ?? ''); }

$suggest = $fillable ? s209_suggestions($pdo, $incidentId, $opId) : [];

// Blocks 48-53: the saved grid; otherwise (while editable) the live resources pulled from check-in / equipment records.
$resStored = $formRecordId > 0 ? s209res_load($pdo, $formRecordId) : null;
$resNote = ''; $resStale = false;
if (isset($resGridOverride)) {
    $resGrid = $resGridOverride; $resNote = 'Your unsaved edits are shown.';
} elseif ($resStored) {
    $resGrid = $resStored['grid'];
    $resNote = ($resStored['source'] === 'edited' ? 'Edited by hand' : 'Built from current resources') . ($resStored['updated_at'] !== '' ? ' - saved ' . $resStored['updated_at'] : '');
    if ($fillable) { $resStale = $resStored['auto_hash'] !== '' && $resStored['auto_hash'] !== s209res_hash(s209res_collect($pdo, $incidentId)); }
} elseif ($fillable) {
    $resGrid = s209res_collect($pdo, $incidentId);
    $resNote = s209res_has_data($resGrid) ? 'Pulled automatically from ICS 211 check-ins and ICS 218 equipment (resources already released in the demob tracker are left out). Review and edit, then save.'
        : 'No checked-in resources were found yet. Fill this table in by hand or use "Refresh from current resources" once check-in has data.';
} else {
    $resGrid = s209res_empty_grid();
}
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$carriedCount = count(array_filter($source, fn($s) => strpos($s, 'carried') === 0));

$positions = [];
try {
    $positions = function_exists('get_roles') ? get_roles() : $pdo->query("SELECT r.id, r.role_code AS code, COALESCE(pc.full_title, r.role_name) AS name,
        COALESCE(pc.group_name, 'Other') AS group_name FROM roles r LEFT JOIN incident_position_catalog pc ON pc.acronym = r.role_code AND pc.is_active = 1
        ORDER BY COALESCE(pc.group_sort, 99), COALESCE(pc.sort_order, 9999), r.role_code")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $positions = []; }

// ---------------------------------------------------------------------------------------------------------------------
// Rendering helpers
// ---------------------------------------------------------------------------------------------------------------------
$v = function (string $k) use (&$values): string { return (string)($values[$k] ?? ''); };
$note = function (string $k) use (&$source): string {
    return isset($source[$k]) ? ' <span class="s209-src" title="Pre-filled - review before saving">(' . s209_h($source[$k]) . ')</span>' : '';
};
$suggestButtons = function (string $k) use (&$suggest): string {
    $html = '';
    foreach ($suggest[$k] ?? [] as $s) {
        $html .= '<button type="button" class="btn btn-sm btn-outline-secondary s209-suggest" data-target="' . s209_h($k) . '" data-text="' . s209_h($s['text']) . '" title="'
               . s209_h(mb_substr($s['text'], 0, 160)) . '">Insert from ' . s209_h($s['label']) . '</button> ';
    }
    return $html === '' ? '' : '<div class="s209-suggest-row">' . $html . '</div>';
};
$input = function (string $k, string $label, array $o = []) use ($v, $note): void {
    $span = (int)($o['span'] ?? 4);
    echo '<div class="s209-cell" style="grid-column: span ' . $span . '"><label for="f_' . s209_h($k) . '">' . s209_h($label) . $note($k) . '</label>';
    echo '<input type="text" id="f_' . s209_h($k) . '" name="' . s209_h($k) . '" value="' . s209_h($v($k)) . '"'
       . (!empty($o['placeholder']) ? ' placeholder="' . s209_h($o['placeholder']) . '"' : '') . (!empty($o['readonly']) ? ' readonly' : '') . '></div>';
};
$area = function (string $k, string $label, array $o = []) use ($v, $note, $suggestButtons): void {
    $span = (int)($o['span'] ?? 12);
    echo '<div class="s209-cell" style="grid-column: span ' . $span . '"><label for="f_' . s209_h($k) . '">' . s209_h($label) . $note($k) . '</label>';
    echo $suggestButtons($k);
    echo '<textarea id="f_' . s209_h($k) . '" name="' . s209_h($k) . '" rows="' . (int)($o['rows'] ?? 4) . '">' . s209_h($v($k)) . '</textarea></div>';
};
$positionSelect = function (string $k, string $label, int $span = 4) use ($v, $note, $positions): void {
    echo '<div class="s209-cell" style="grid-column: span ' . $span . '"><label for="f_' . s209_h($k) . '">' . s209_h($label) . $note($k) . '</label><select id="f_' . s209_h($k) . '" name="' . s209_h($k) . '"><option value="">-- Select ICS Position --</option>';
    foreach ($positions as $p) {
        echo '<option value="' . (int)$p['id'] . '"' . ((string)$v($k) === (string)$p['id'] ? ' selected' : '') . '>'
           . s209_h(($p['group_name'] ?? 'Other') . ' / ' . ($p['code'] ?? '') . ' - ' . ($p['name'] ?? '')) . '</option>';
    }
    echo '</select></div>';
};
$section = function (string $title, bool $open = true): void { echo '<details class="card s209-section"' . ($open ? ' open' : '') . '><summary><strong>' . s209_h($title) . '</strong></summary><div class="s209-grid">'; };
$endSection = function (): void { echo '</div></details>'; };
$cellTable = function (string $prefix, array $rows, array $columns, string $firstHead) use ($v): void {
    echo '<div class="s209-cell" style="grid-column: span 12"><table class="s209-table"><thead><tr><th>' . s209_h($firstHead) . '</th>';
    foreach ($columns as $c => $label) { echo '<th>' . s209_h($label) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($rows as $k => $rowLabel) {
        echo '<tr><td>' . s209_h($rowLabel) . '</td>';
        foreach ($columns as $c => $_) { $n = $prefix . $k . '_' . $c; echo '<td><input type="text" name="' . s209_h($n) . '" value="' . s209_h($v($n)) . '" aria-label="' . s209_h($rowLabel . ' ' . $c) . '"></td>'; }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};
$horizonArea = function (string $prefix, string $title) use ($horizons, $area): void {
    foreach ($horizons as $k => $label) { $area("{$prefix}_{$k}", $title . ' - ' . $label, ['rows' => 3]); }
};

$fieldsDisabled = ($locked || !$canEdit) ? ' disabled' : '';
$pdfOptions = ['incident_id' => $incidentId, 'op_id' => $opId];

render_header(S209_TITLE);
?>
<style>
.s209-section { margin-bottom: 14px; }
.s209-section > summary { cursor: pointer; font-size: 1.05rem; padding: 2px 0; }
.s209-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
.s209-cell label { display: block; font-weight: 600; margin-bottom: 4px; }
.s209-cell input[type=text], .s209-cell textarea, .s209-cell select { width: 100%; box-sizing: border-box; }
.s209-cell textarea { resize: vertical; }
.s209-src { font-weight: 400; font-size: .8em; color: #0b5ed7; }
.s209-suggest-row { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.s209-table { width: 100%; border-collapse: collapse; }
.s209-table th, .s209-table td { border: 1px solid #ccc; padding: 4px 6px; }
.s209-table th { background: #f3f3f3; text-align: left; }
.s209-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 6px; }
.s209-checks label { font-weight: 400; display: flex; gap: 6px; align-items: center; }
.s209-radios label { display: inline-flex; align-items: center; gap: 6px; margin-right: 16px; font-weight: 400; }
.s209-radios input[type=radio] { width: 14px; height: 14px; min-width: 0; margin: 0; padding: 0; flex: 0 0 auto; }
fieldset.s209-fs { border: 0; margin: 0; padding: 0; min-width: 0; }
@media (max-width: 760px) { .s209-grid { grid-template-columns: 1fr; } .s209-cell { grid-column: 1 / -1 !important; } }
</style>
<?php
render_ics_form_status(S209_FORM, $formRecord, $preparer, $signature, [
    'title' => S209_TITLE,
    'official_file' => S209_WF ? '/assets/pdf/official/ICS209-wf.pdf' : '/assets/pdf/official/ICS209.pdf',
    'status' => $formStatus,
    'error' => $error,
    'note' => 'ICS 209 is normally prepared by the Situation Unit Leader (SITL) or Planning Section staff and approved by the Planning Section Chief and/or Incident Commander. This form remains a live draft until submitted.',
]);
?>
<div class="card no-print">
  <p><strong>Report:</strong> #<?= s209_h($v('report_number')) ?> (<?= s209_h($v('report_version')) ?>)
     &nbsp;|&nbsp; <strong>Operational Period:</strong> <?= $opRow ? s209_h(operational_period_label($opRow)) : '<span class="muted">none selected</span>' ?></p>
  <?php if (!$opRow): ?><div class="flash error">Select or create an operational period before using this form.</div><?php endif; ?>
  <?php if ($fillable && $carriedCount > 0): ?>
    <p class="small">Pre-filled from report #<?= (int)$prevNumber ?> and from other incident data. Items marked <span class="s209-src">(blue notes)</span> were filled in for you; nothing is final until you save. Period-specific narrative and sign-off fields start blank.</p>
  <?php elseif ($fillable && $source): ?>
    <p class="small">Blue notes show fields filled in from the incident record, assigned command staff and the operational period. Nothing is final until you save.</p>
  <?php endif; ?>
  <?php if (!empty($formRecord['review_required']) && $fillable): ?><p class="small priority-high">This report was copied forward into a new operational period and needs review.</p><?php endif; ?>
</div>

<form method="post" action="<?= s209_h(app_url(S209_URL)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="incident_id" value="<?= (int)$incidentId ?>">
  <input type="hidden" name="operational_period_id" value="<?= (int)$opId ?>">
  <?php if ($requestedRecordId > 0): ?><input type="hidden" name="record_id" value="<?= (int)$requestedRecordId ?>"><?php endif; ?>
  <fieldset class="s209-fs"<?= $fieldsDisabled ?>>

<?php $section('1-15  Approval & Routing Information'); ?>
    <?php $input('incident_name', '1. Incident Name', ['span' => 6]); $input('incident_no', '2. Incident No.', ['span' => 3]); ?>
    <div class="s209-cell" style="grid-column: span 3"><label>3. Report Version<?= $note('report_version') ?></label>
      <div class="s209-radios"><?php foreach (['Initial', 'Update', 'Final'] as $ver): ?>
        <label><input type="radio" name="report_version" value="<?= $ver ?>"<?= $v('report_version') === $ver ? ' checked' : '' ?>> <?= $ver ?></label>
      <?php endforeach; ?></div></div>
    <?php $input('report_number', 'Report #', ['span' => 2, 'readonly' => true]); ?>
    <?php $area('incident_commanders', '4. Incident Commander(s) & Agency or Organization', ['span' => 6, 'rows' => 3]); ?>
    <?php $input('incident_management_org', '5. Incident Management Organization', ['span' => 4]); ?>
    <?php $input('incident_start_date', '6. Incident Start Date', ['span' => 3, 'placeholder' => 'YYYY-MM-DD']); $input('incident_start_time', 'Time', ['span' => 2, 'placeholder' => 'HH:MM']); ?>
    <?php $input('current_size_area', '7. Current Incident Size or Area Involved', ['span' => 3]); $input('percent_contained', '8. Percent (%) Contained / Completed', ['span' => 3]); ?>
    <?php if (S209_WF): ?>
    <div class="s209-cell" style="grid-column: span 3"><label>9. Status<?= $note('fire_status') ?></label>
      <div class="s209-radios"><?php foreach (['OC' => 'OC - Out of Control', 'BH' => 'BH - Being Held', 'UC' => 'UC - Under Control', 'O' => 'O - Out'] as $code => $lbl): ?>
        <label><input type="radio" name="fire_status" value="<?= $code ?>"<?= $v('fire_status') === $code ? ' checked' : '' ?>> <?= s209_h($lbl) ?></label>
      <?php endforeach; ?></div></div>
    <?php else: $input('incident_definition', '9. Incident Definition', ['span' => 3]); endif; ?>
    <?php $input('complexity_level', '10. Incident Complexity Level', ['span' => 3]); ?>
    <?php $input('period_from', '11. For Time Period - From Date/Time', ['span' => 3, 'placeholder' => 'YYYY-MM-DD HH:MM']); $input('period_to', 'To Date/Time', ['span' => 3, 'placeholder' => 'YYYY-MM-DD HH:MM']); ?>
    <?php $input('prepared_by_name', '12. Prepared By - Print Name', ['span' => 3]); $positionSelect('prepared_by_position_id', 'ICS Position', 3); $input('prepared_by_signature', 'Signature', ['span' => 3]); $input('prepared_datetime', 'Date/Time Prepared', ['span' => 3]); ?>
    <?php $input('submitted_datetime', '13. Date/Time Submitted', ['span' => 4]); ?>
    <?php $input('approved_by_name', '14. Approved By - Print Name', ['span' => 3]); $positionSelect('approved_by_position_id', 'ICS Position', 3); $input('approved_by_signature', 'Signature', ['span' => 3]); $input('approved_datetime', 'Date/Time Approved', ['span' => 3]); ?>
    <?php $input('primary_location_sent_to', '15. Primary Location, Organization, or Agency Sent To', ['span' => 12]); ?>
<?php $endSection(); ?>

<?php $section('16-27  Incident Location Information'); ?>
    <?php $input('province_territory', '16. Province/Territory', ['span' => 4]); $input('county_regional', '17. County, Regional/Rural Municipality, Regional/Municipal District', ['span' => 4]); $input('city', '18. City', ['span' => 4]); ?>
    <?php $input('unit_other', '19. Unit or Other', ['span' => 4]); $input('incident_jurisdiction', '20. Incident Jurisdiction', ['span' => 4]); $input('location_ownership', '21. Incident Location Ownership', ['span' => 4]); ?>
    <?php $input('longitude', '22. Longitude', ['span' => 3]); $input('latitude', 'Latitude', ['span' => 3]); $input('datum', '23. Datum', ['span' => 3]); $input('utm_coordinates', '26. UTM Coordinates', ['span' => 3]); ?>
    <?php $area('legal_description', '24. Legal Description', ['span' => 6, 'rows' => 3]); $area('short_location_desc', '25. Short Location or Area Description', ['span' => 6, 'rows' => 3]); ?>
    <?php $area('geospatial_data', '27. Electronic Geospatial Data Included or Attached', ['rows' => 2]); ?>
<?php $endSection(); ?>

<?php $section('28-30  Incident Summary'); ?>
    <?php $area('significant_events', S209_WF ? '28. Observed Fire Behaviour or Significant Events for the Time Period Reported' : '28. Significant Events for the Time Period Reported', ['rows' => 6]); ?>
    <?php $area('primary_materials_hazards', S209_WF ? '29. Primary FBP Fuel Type, Materials or Hazards Involved' : '29. Primary Materials or Hazards Involved', ['rows' => 4]); ?>
    <?php $area('damage_assessment_info', '30. Damage Assessment Information', ['rows' => 4]); ?>
    <?php $cellTable('damage_', $damageRows, ['threatened' => '# Threatened (72 hrs)', 'damaged' => '# Damaged', 'destroyed' => '# Destroyed'], 'Structural Summary'); ?>
<?php $endSection(); ?>

<?php $section('31-35  Life, Safety & Health'); ?>
    <div class="s209-cell" style="grid-column: span 6"><strong>31. Public Status Summary (civilians)</strong></div>
    <div class="s209-cell" style="grid-column: span 6"><strong>32. Responder Status Summary</strong></div>
    <div class="s209-cell" style="grid-column: span 6"><?php $dummy = null; ?>
      <table class="s209-table"><thead><tr><th>Public</th><th>This Period</th><th>Total to Date</th></tr></thead><tbody>
      <?php foreach ($statusRows as $k => $lbl): ?><tr><td><?= s209_h($lbl) ?></td>
        <td><input type="text" name="public_<?= $k ?>_period" value="<?= s209_h($v("public_{$k}_period")) ?>"></td>
        <td><input type="text" name="public_<?= $k ?>_total" value="<?= s209_h($v("public_{$k}_total")) ?>"></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="s209-cell" style="grid-column: span 6">
      <table class="s209-table"><thead><tr><th>Responders</th><th>This Period</th><th>Total to Date</th></tr></thead><tbody>
      <?php foreach ($statusRows as $k => $lbl): ?><tr><td><?= s209_h($lbl) ?></td>
        <td><input type="text" name="responder_<?= $k ?>_period" value="<?= s209_h($v("responder_{$k}_period")) ?>"></td>
        <td><input type="text" name="responder_<?= $k ?>_total" value="<?= s209_h($v("responder_{$k}_total")) ?>"></td></tr><?php endforeach; ?></tbody></table></div>
    <?php $area('life_safety_remarks', '33. Life, Safety, and Health Status/Threat Remarks', ['rows' => 4]); ?>
    <div class="s209-cell" style="grid-column: span 12"><label>34. Life, Safety, and Health Threat Management - Check if Active<?= isset($source['threat_no_likely_threat']) || array_intersect_key($source, $threats) ? ' <span class="s209-src">(carried from report #' . (int)$prevNumber . ')</span>' : '' ?></label>
      <div class="s209-checks"><?php foreach ($threats as $k => $lbl): ?>
        <label><input type="checkbox" name="<?= $k ?>" value="1"<?= !empty($values[$k]) ? ' checked' : '' ?>> <?= s209_h($lbl) ?></label>
      <?php endforeach; ?></div></div>
    <?php $area('weather_concerns', '35. Weather Concerns', ['rows' => 3]); ?>
<?php $endSection(); ?>

<?php $section('36-37  Projected Activity & Objectives'); ?>
    <?php $horizonArea('projected_activity', '36. Projected Incident Activity, Potential, Movement, Escalation, or Spread'); ?>
    <?php $area('objectives', S209_WF ? '37. Strategic Objectives (planned end-state for the incident)' : '37. Objectives', ['rows' => 6]); ?>
<?php $endSection(); ?>

<?php $section('38-41  Additional Incident Decision Support Information'); ?>
    <?php $horizonArea('threat_risk', '38. Current Incident Threat Summary and Risk Information'); ?>
    <?php $horizonArea('critical_resource_needs', '39. Critical Resource Needs'); ?>
    <?php $area('strategic_discussion', '40. Strategic Discussion', ['rows' => 5]); ?>
    <?php $area('planned_actions', '41. Planned Actions for Next Operational Period', ['rows' => 5]); ?>
<?php $endSection(); ?>

<?php $section('42-47  Projections, Costs & Remarks'); ?>
    <?php $input('projected_final_size', '42. Projected Final Incident Size/Area', ['span' => 4]); $input('anticipated_completion_date', '43. Anticipated Incident Management Completion Date', ['span' => 4]); $input('demob_start_date', '44. Projected Significant Resource Demobilization Start Date', ['span' => 4]); ?>
    <?php $input('estimated_costs_to_date', '45. Estimated Incident Costs to Date', ['span' => 6]); $input('projected_final_cost', '46. Projected Final Incident Cost Estimate', ['span' => 6]); ?>
    <?php $area('remarks', '47. Remarks', ['rows' => 5]); ?>
<?php $endSection(); ?>

<?php $section('48-53  Incident Resource Commitment Summary'); ?>
    <?php s209res_render($resGrid, $fillable, $resNote, $resStale); ?>
<?php $endSection(); ?>

  </fieldset>
<?php
render_ics_form_actions([
    'form_code' => S209_FORM,
    'form_title' => S209_TITLE,
    'form_record_id' => $formRecordId,
    'permissions' => $permissions,
    'status' => $formStatus,
    'is_final' => $locked,
    'incident_id' => $incidentId,
    'op_id' => $opId,
    'action_field' => 'action',
    'save_value' => 'save',
    'submit_value' => 'submit',
    'blank_download_url' => is_file(dirname(__DIR__, 2) . (S209_WF ? '/assets/pdf/official/ICS209-wf.pdf' : '/assets/pdf/official/ICS209.pdf'))
        ? app_url(S209_WF ? '/assets/pdf/official/ICS209-wf.pdf' : '/assets/pdf/official/ICS209.pdf') : null,
]);
?>
</form>
<script>
document.querySelectorAll('.s209-suggest').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var ta = document.querySelector('[name="' + btn.dataset.target + '"]');
    if (!ta) { return; }
    var text = btn.dataset.text || '';
    ta.value = ta.value.trim() === '' ? text : ta.value.replace(/\s+$/, '') + '\n\n' + text;
    ta.focus();
  });
});
</script>
<?php render_footer(); ?>
