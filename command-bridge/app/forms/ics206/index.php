<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once __DIR__ . '/../../includes/form_action_buttons.php'; ?>
<?php

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);

if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$user = current_user();
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS206', $incidentId) : get_form_permission_defaults('ICS206');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS206') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$op = $incidentId > 0 ? current_operational_period($incidentId) : null;
$opId = (int)($op['id'] ?? 0);
$error = null;

if ($incidentId > 0 && !$canView) { http_response_code(403); render_header('ICS 206 - Medical Plan'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }

function ics206_user_has_role(PDO $pdo, int $incidentId, array $codes): bool {
    $u = current_user(); if (!$u) return false;
    if (!empty($u['is_tsc_admin'])) return true;
    $uid = (int)($u['id'] ?? 0); if ($incidentId <= 0 || $uid <= 0) return false;
    try {
        $codes = array_values(array_unique(array_map('strtoupper', $codes)));
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM incident_positions ip INNER JOIN roles r ON r.id=ip.role_id WHERE ip.incident_id=? AND ip.user_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) AND UPPER(r.role_code) IN ($ph)");
        $st->execute(array_merge([$incidentId,$uid], $codes));
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) { return in_array(strtoupper((string)($u['role_code'] ?? '')), $codes, true); }
}
function ics206_can_submit(PDO $pdo, int $incidentId): bool { return ics206_user_has_role($pdo, $incidentId, ['IC','MEDL','SBD','LSC']); }
function ics206_op_label(?array $op): string {
    if (!$op) return 'No operational period selected';
    $parts=[]; if(!empty($op['period_number'])) $parts[]='OP '.(int)$op['period_number']; if(!empty($op['period_name'])) $parts[]=(string)$op['period_name'];
    $range=trim((string)($op['op_start']??$op['start_time']??'').' to '.(string)($op['op_end']??$op['end_time']??''));
    return trim(($parts?implode(' - ',$parts).' | ':'').$range);
}
function arrv($arr,$i,$key){ return trim((string)($arr[$key][$i] ?? '')); }

$canSubmit = ics206_can_submit($pdo, $incidentId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0) {
    $action = (string)($_POST['action'] ?? 'save');
    if (!$canEdit && !($action === 'submit' && $canSubmit)) { http_response_code(403); $error='Forbidden'; }
    else {
        try {
            $pdo->beginTransaction();
            if ($formRecordId <= 0) {
                $stmt = $pdo->prepare("INSERT INTO form_records (incident_id, form_type, form_number, status, prepared_by_user_id, prepared_at) VALUES (?, 'ICS206', '206', 'draft', ?, NOW())");
                $stmt->execute([$incidentId, $user['id'] ?? null]);
                $formRecordId = (int)$pdo->lastInsertId();
            }
            if ($action === 'submit' && $canSubmit) { $pdo->prepare("UPDATE form_records SET status='submitted' WHERE id=?")->execute([$formRecordId]); }

            $stmt=$pdo->prepare("INSERT INTO form_206_headers (form_record_id, operational_period_id, medical_emergency_procedures, prepared_by_name, prepared_by_position_title) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE operational_period_id=VALUES(operational_period_id), medical_emergency_procedures=VALUES(medical_emergency_procedures), prepared_by_name=VALUES(prepared_by_name), prepared_by_position_title=VALUES(prepared_by_position_title)");
            $stmt->execute([$formRecordId, $opId ?: null, trim($_POST['medical_emergency_procedures'] ?? ''), trim($_POST['prepared_by_name'] ?? ''), trim($_POST['prepared_by_position_title'] ?? '')]);
            $headerId=(int)$pdo->lastInsertId();
            if ($headerId<=0){ $s=$pdo->prepare("SELECT id FROM form_206_headers WHERE form_record_id=?"); $s->execute([$formRecordId]); $headerId=(int)$s->fetchColumn(); }

            foreach (['form_206_aid_stations','form_206_transportation','form_206_hospitals'] as $t) { $pdo->prepare("DELETE FROM `$t` WHERE header_id=?")->execute([$headerId]); }

            $aid=$_POST['aid'] ?? []; $n=max(1,count($aid['name'] ?? []));
            $ins=$pdo->prepare("INSERT INTO form_206_aid_stations (header_id, sort_order, name, location, contact, paramedics) VALUES (?,?,?,?,?,?)");
            for($i=0;$i<$n;$i++){ $vals=[arrv($aid,$i,'name'),arrv($aid,$i,'location'),arrv($aid,$i,'contact'),arrv($aid,$i,'paramedics')==='1'?1:0]; if(implode('',$vals)!=='0') $ins->execute([$headerId,$i,$vals[0],$vals[1],$vals[2],$vals[3]]); }

            $tr=$_POST['transport'] ?? []; $n=max(1,count($tr['ambulance_service'] ?? []));
            $ins=$pdo->prepare("INSERT INTO form_206_transportation (header_id, sort_order, ambulance_service, location, contact, level_of_service) VALUES (?,?,?,?,?,?)");
            for($i=0;$i<$n;$i++){ $vals=[arrv($tr,$i,'ambulance_service'),arrv($tr,$i,'location'),arrv($tr,$i,'contact'),arrv($tr,$i,'level_of_service')]; if(implode('',$vals)!=='') $ins->execute([$headerId,$i,$vals[0],$vals[1],$vals[2],$vals[3]]); }

            $hos=$_POST['hospital'] ?? []; $n=max(1,count($hos['hospital_name'] ?? []));
            $ins=$pdo->prepare("INSERT INTO form_206_hospitals (header_id, sort_order, hospital_name, address, travel_time_air, travel_time_ground, contact, helipad, burn_centre) VALUES (?,?,?,?,?,?,?,?,?)");
            for($i=0;$i<$n;$i++){ $vals=[arrv($hos,$i,'hospital_name'),arrv($hos,$i,'address'),arrv($hos,$i,'travel_time_air'),arrv($hos,$i,'travel_time_ground'),arrv($hos,$i,'contact'),arrv($hos,$i,'helipad')==='1'?1:0,arrv($hos,$i,'burn_centre')==='1'?1:0]; if(($vals[0].$vals[1].$vals[2].$vals[3].$vals[4])!=='' || $vals[5] || $vals[6]) $ins->execute([$headerId,$i,$vals[0],$vals[1],$vals[2],$vals[3],$vals[4],$vals[5],$vals[6]]); }

            $pdo->commit(); header('Location: index.php?saved=1'); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $e->getMessage(); }
    }
}

$data=[]; $aid=[]; $transport=[]; $hospitals=[];
if ($formRecordId > 0) {
    $stmt=$pdo->prepare("SELECT * FROM form_206_headers WHERE form_record_id=?"); $stmt->execute([$formRecordId]); $data=$stmt->fetch() ?: [];
    if($data){ foreach([['aid','form_206_aid_stations'],['transport','form_206_transportation'],['hospitals','form_206_hospitals']] as $x){ $st=$pdo->prepare("SELECT * FROM {$x[1]} WHERE header_id=? ORDER BY sort_order,id"); $st->execute([(int)$data['id']]); ${$x[0]}=$st->fetchAll(); } }
}
if (!$aid) $aid=[[]]; if (!$transport) $transport=[[]]; if (!$hospitals) $hospitals=[[]];

render_header('ICS 206 - Medical Plan');
?>
<?php
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS206') : ['name'=>'TBD','position_title'=>'MEDL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
render_ics_form_status('ICS206', $formRecord, $preparer, $signature, [
  'error' => $error,
  'show_print' => false,
  'show_pdf' => $formRecordId > 0,
  'pdf_url' => '/forms/ics206/pdf.php',
  'note' => 'This form remains editable while it is in draft.'
]);
?>
<div class="card">
  <h2>Medical Plan (ICS 206)</h2>
  <?php if (isset($_GET['saved'])): ?><p class="notice">Saved.</p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-meta">
      <label>Incident Name<input readonly value="<?= h($incident['incident_name'] ?? $incident['name'] ?? '') ?>"></label>
      <label>Operational Period<input readonly value="<?= h(ics206_op_label($op)) ?>"></label>
      <label>Date/Time Prepared<input readonly value="<?= h(date('Y-m-d H:i')) ?>"></label>
    </div>

    <h3>4. Incident Medical Aid Station</h3>
    <table class="ics-table" id="aidTable"><thead><tr><th>Medical Aid Stations</th><th>Location</th><th>Contact (number or frequency)</th><th>Paramedics</th><th></th></tr></thead><tbody>
    <?php foreach($aid as $r): ?><tr><td><input name="aid[name][]" value="<?= h($r['name']??'') ?>"></td><td><input name="aid[location][]" value="<?= h($r['location']??'') ?>"></td><td><input name="aid[contact][]" value="<?= h($r['contact']??'') ?>"></td><td><select name="aid[paramedics][]"><option value="0">No</option><option value="1" <?= !empty($r['paramedics'])?'selected':'' ?>>Yes</option></select></td><td><button type="button" onclick="removeRow(this)">Remove</button></td></tr><?php endforeach; ?>
    </tbody></table><button type="button" onclick="addAid()">Add Medical Aid Station</button>

    <h3>5. Transportation (indicate air or ground)</h3>
    <table class="ics-table" id="transportTable"><thead><tr><th>Ambulance Service</th><th>Location</th><th>Contact (number or frequency)</th><th>Level of Serv.</th><th></th></tr></thead><tbody>
    <?php foreach($transport as $r): ?><tr><td><input name="transport[ambulance_service][]" value="<?= h($r['ambulance_service']??'') ?>"></td><td><input name="transport[location][]" value="<?= h($r['location']??'') ?>"></td><td><input name="transport[contact][]" value="<?= h($r['contact']??'') ?>"></td><td><input name="transport[level_of_service][]" value="<?= h($r['level_of_service']??'') ?>" placeholder="BLS / ALS / Air / Ground"></td><td><button type="button" onclick="removeRow(this)">Remove</button></td></tr><?php endforeach; ?>
    </tbody></table><button type="button" onclick="addTransport()">Add Transportation Resource</button>

    <h3>6. Hospitals</h3>
    <table class="ics-table" id="hospitalTable"><thead><tr><th>Hospital Name</th><th>Address (Lat. and Long. if Helipad)</th><th>Travel Air Time</th><th>Travel Ground Time</th><th>Contact</th><th>Helipad</th><th>Burn Ctr.</th><th></th></tr></thead><tbody>
    <?php foreach($hospitals as $r): ?><tr><td><input name="hospital[hospital_name][]" value="<?= h($r['hospital_name']??'') ?>"></td><td><input name="hospital[address][]" value="<?= h($r['address']??'') ?>"></td><td><input name="hospital[travel_time_air][]" value="<?= h($r['travel_time_air']??'') ?>"></td><td><input name="hospital[travel_time_ground][]" value="<?= h($r['travel_time_ground']??'') ?>"></td><td><input name="hospital[contact][]" value="<?= h($r['contact']??'') ?>"></td><td><select name="hospital[helipad][]"><option value="0">No</option><option value="1" <?= !empty($r['helipad'])?'selected':'' ?>>Yes</option></select></td><td><select name="hospital[burn_centre][]"><option value="0">No</option><option value="1" <?= !empty($r['burn_centre'])?'selected':'' ?>>Yes</option></select></td><td><button type="button" onclick="removeRow(this)">Remove</button></td></tr><?php endforeach; ?>
    </tbody></table><button type="button" onclick="addHospital()">Add Hospital</button>

    <h3>7. Special Medical Emergency Procedures</h3>
    <textarea name="medical_emergency_procedures"><?= h($data['medical_emergency_procedures'] ?? '') ?></textarea>
    <h3>8. Prepared By</h3>
    <label>Name<input name="prepared_by_name" value="<?= h($data['prepared_by_name'] ?? ($user['display_name'] ?? $user['name'] ?? '')) ?>"></label>
    <label>Position/Title<input name="prepared_by_position_title" value="<?= h($data['prepared_by_position_title'] ?? '') ?>" placeholder="Medical Unit Leader"></label>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS206',
        'form_title' => 'ICS206',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
</div>
<script>
function removeRow(btn){const tb=btn.closest('tbody'); if(tb.rows.length>1) btn.closest('tr').remove();}
function addAid(){document.querySelector('#aidTable tbody').insertAdjacentHTML('beforeend','<tr><td><input name="aid[name][]"></td><td><input name="aid[location][]"></td><td><input name="aid[contact][]"></td><td><select name="aid[paramedics][]"><option value="0">No</option><option value="1">Yes</option></select></td><td><button type="button" onclick="removeRow(this)">Remove</button></td></tr>');}
function addTransport(){document.querySelector('#transportTable tbody').insertAdjacentHTML('beforeend','<tr><td><input name="transport[ambulance_service][]"></td><td><input name="transport[location][]"></td><td><input name="transport[contact][]"></td><td><input name="transport[level_of_service][]" placeholder="BLS / ALS / Air / Ground"></td><td><button type="button" onclick="removeRow(this)">Remove</button></td></tr>');}
function addHospital(){document.querySelector('#hospitalTable tbody').insertAdjacentHTML('beforeend','<tr><td><input name="hospital[hospital_name][]"></td><td><input name="hospital[address][]"></td><td><input name="hospital[travel_time_air][]"></td><td><input name="hospital[travel_time_ground][]"></td><td><input name="hospital[contact][]"></td><td><select name="hospital[helipad][]"><option value="0">No</option><option value="1">Yes</option></select></td><td><select name="hospital[burn_centre][]"><option value="0">No</option><option value="1">Yes</option></select></td><td><button type="button" onclick="removeRow(this)">Remove</button></td></tr>');}
</script>
<?php render_footer(); ?>
