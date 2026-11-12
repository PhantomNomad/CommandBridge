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
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS204', $incidentId) : get_form_permission_defaults('ICS204');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS204') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$error = null;
$user = current_user();
$op = $incidentId > 0 ? current_operational_period($incidentId) : null;
$opId = (int)($op['id'] ?? 0);

if ($incidentId > 0 && !$canView) { http_response_code(403); render_header('ICS 204'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }

function ics204_user_has_role(PDO $pdo, int $incidentId, array $codes): bool {
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
function ics204_can_submit(PDO $pdo, int $incidentId): bool { return ics204_user_has_role($pdo, $incidentId, ['IC','RESL']); }
function ics204_can_psc_approve(PDO $pdo, int $incidentId): bool { return ics204_user_has_role($pdo, $incidentId, ['PSC']); }
function ics204_name_for_role(PDO $pdo, int $incidentId, array $codes): string {
    try {
        $ph=implode(',', array_fill(0,count($codes),'?'));
        $st=$pdo->prepare("SELECT COALESCE(NULLIF(u.real_name,''),u.username) FROM incident_positions ip INNER JOIN roles r ON r.id=ip.role_id LEFT JOIN users u ON u.id=ip.user_id AND u.deleted_at IS NULL WHERE ip.incident_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) AND UPPER(r.role_code) IN ($ph) LIMIT 1");
        $st->execute(array_merge([$incidentId], array_map('strtoupper',$codes)));
        return (string)($st->fetchColumn() ?: '');
    } catch(Throwable $e){ return ''; }
}
function ics204_notify_psc(PDO $pdo, int $incidentId, int $formRecordId): void {
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(), 'Field');
        $title = 'ICS 204 requires Planning Section Chief approval';
        $body = 'An ICS 204 Assignment List has been submitted and requires PSC approval.';
        $link = app_url('/forms/ics204/index.php');
        if (in_array('role_code', $cols, true) && in_array('title', $cols, true) && in_array('link_url', $cols, true)) {
            $st=$pdo->prepare("INSERT INTO notifications (incident_id,user_id,role_code,title,body,link_url,created_at,is_read) VALUES (?,?,?,?,?,?,NOW(),0)");
            $st->execute([$incidentId, null, 'PSC', $title, $body, $link]);
            return;
        }
        if (in_array('subject', $cols, true) && in_array('notification_type', $cols, true)) {
            $st=$pdo->prepare("SELECT DISTINCT ip.user_id FROM incident_positions ip INNER JOIN roles r ON r.id=ip.role_id WHERE ip.incident_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) AND UPPER(r.role_code)='PSC' AND ip.user_id IS NOT NULL");
            $st->execute([$incidentId]);
            $ins=$pdo->prepare("INSERT INTO notifications (incident_id,user_id,form_record_id,notification_type,subject,body,is_read,created_at) VALUES (?,?,?,?,?,?,0,NOW())");
            foreach ($st->fetchAll() as $r) { $ins->execute([$incidentId,(int)$r['user_id'],$formRecordId,'approval_required',$title,$body]); }
        }
    } catch(Throwable $e) {}
}
function ics204_ensure_record(PDO $pdo, int $incidentId, int $opId): int {
    $fr=get_form_record($incidentId,'ICS204'); $id=(int)($fr['id'] ?? 0);
    if($id<=0){ $st=$pdo->prepare("INSERT INTO form_records (incident_id,form_type,form_number,status,prepared_by_user_id,prepared_at) VALUES (?,'ICS204','204','draft',?,NOW())"); $st->execute([$incidentId,current_user()['id'] ?? null]); $id=(int)$pdo->lastInsertId(); }
    return $id;
}

$canSubmit204 = $incidentId > 0 && ics204_can_submit($pdo, $incidentId);
$canApprove204 = $incidentId > 0 && ics204_can_psc_approve($pdo, $incidentId);
$incidentName = (string)($incident['incident_name'] ?? $incident['name'] ?? '');
$opDates = $op ? operational_period_label($op) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $incidentId > 0) {
    try {
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'submit_ics204' && !$canSubmit204) { http_response_code(403); throw new RuntimeException('Only the Incident Commander, Technical Services Coordinator/Admin, or Resources Unit Leader may submit ICS 204.'); }
        if ($action === 'approve_psc' && !$canApprove204) { http_response_code(403); throw new RuntimeException('Only the Planning Section Chief or Technical Services Coordinator/Admin may approve ICS 204.'); }
        $pdo->beginTransaction();
        $formRecordId = ics204_ensure_record($pdo, $incidentId, $opId);
        $stmt=$pdo->prepare("INSERT INTO form_204_headers (form_record_id, operational_period_id, branch_name, division_group, operations_personnel, work_assignments, special_instructions, medical_plan_summary, safety_message_summary, prepared_by_user_id, prepared_by_name, prepared_by_position_title, approved_by_user_id, approved_by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE operational_period_id=VALUES(operational_period_id), branch_name=VALUES(branch_name), division_group=VALUES(division_group), operations_personnel=VALUES(operations_personnel), work_assignments=VALUES(work_assignments), special_instructions=VALUES(special_instructions), medical_plan_summary=VALUES(medical_plan_summary), safety_message_summary=VALUES(safety_message_summary), prepared_by_user_id=VALUES(prepared_by_user_id), prepared_by_name=VALUES(prepared_by_name), prepared_by_position_title=VALUES(prepared_by_position_title)");
        $prepName = trim($_POST['prepared_by_name'] ?? '') ?: (string)($user['real_name'] ?? $user['username'] ?? '');
        $stmt->execute([$formRecordId,$opId ?: null,trim($_POST['branch_name']??''),trim($_POST['division_group']??''),trim($_POST['operations_personnel']??''),trim($_POST['work_assignments']??''),trim($_POST['special_instructions']??''),trim($_POST['medical_plan_summary']??''),trim($_POST['safety_message_summary']??''),(int)($user['id']??0),$prepName,trim($_POST['prepared_by_position_title']??'Resources Unit Leader'),null,null]);
        $hid=(int)$pdo->query("SELECT id FROM form_204_headers WHERE form_record_id=".(int)$formRecordId)->fetchColumn();
        $pdo->prepare("DELETE FROM form_204_resources WHERE header_id=?")->execute([$hid]);
        $ins=$pdo->prepare("INSERT INTO form_204_resources (header_id,line_number,resource_identifier,leader_name,number_persons,contact_info,reporting_location) VALUES (?,?,?,?,?,?,?)");
        $resources=$_POST['resources'] ?? [];
        $i=1; foreach($resources as $r){ if(trim(implode('', array_map('strval',(array)$r)))==='') continue; $ins->execute([$hid,$i++,trim($r['resource_identifier']??''),trim($r['leader_name']??''),($r['number_persons']!==''?(int)$r['number_persons']:null),trim($r['contact_info']??''),trim($r['reporting_location']??'')]); }
        if ($action === 'submit_ics204') { $pdo->prepare("UPDATE form_records SET status='submitted', updated_at=NOW() WHERE id=?")->execute([$formRecordId]); ics204_notify_psc($pdo,$incidentId,$formRecordId); }
        if ($action === 'approve_psc') { $pdo->prepare("UPDATE form_204_headers SET approved_by_user_id=?, approved_by_name=? WHERE id=?")->execute([(int)($user['id']??0),(string)($user['real_name'] ?? $user['username'] ?? ''),$hid]); $pdo->prepare("UPDATE form_records SET status='approved', updated_at=NOW() WHERE id=?")->execute([$formRecordId]); }
        $pdo->commit(); header('Location: index.php?saved=1'); exit;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
}

$data=[]; $resources=[];
if ($formRecordId>0) { $st=$pdo->prepare("SELECT * FROM form_204_headers WHERE form_record_id=?"); $st->execute([$formRecordId]); $data=$st->fetch()?:[]; if($data){$st=$pdo->prepare("SELECT * FROM form_204_resources WHERE header_id=? ORDER BY line_number,id");$st->execute([(int)$data['id']]);$resources=$st->fetchAll();}}
if (!$resources) $resources=[['resource_identifier'=>'','leader_name'=>'','number_persons'=>'','contact_info'=>'','reporting_location'=>'']];
$isComplete = $formRecordId > 0 && !empty($data);
$branchOptions = [
    'Operations Branch',
    'Public Works Branch',
    'Fire / Rescue Branch',
    'Law Enforcement Branch',
    'EMS / Medical Branch',
    'Infrastructure Branch',
    'Utilities Branch',
    'Transportation Branch',
    'Agriculture / Rural Services Branch',
    'Evacuation / Reception Centre Branch',
];
$divisionGroupOptions = [
    'Staging Area',
    'North Division',
    'South Division',
    'East Division',
    'West Division',
    'Public Works Group',
    'Utilities Group',
    'Roads Group',
    'Fire Suppression Group',
    'Search and Rescue Group',
    'Evacuation Group',
    'Reception Centre Group',
    'Security Group',
    'Damage Assessment Group',
];
render_header('ICS 204 - Assignment List');
?>
<div class="card no-print"><h2>ICS204 - Assignment List</h2>
<?php if(isset($_GET['saved'])):?><p class="notice">Saved.</p><?php endif;?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
<p><strong>Status:</strong> <?=h(ucfirst((string)($formRecord['status'] ?? 'draft')))?></p>
<p class="small">ICS 204 may be submitted by the Incident Commander, Technical Services Coordinator/Admin, or Resources Unit Leader. Once submitted, the Planning Section Chief receives a dashboard notification and can approve it.</p>
</div>

<div class="card"><h2>Assignment List (ICS 204)</h2><p><a href="help.php">Help / Instructions</a></p>
<form id="ics204form" method="post">
    <?= csrf_field() ?>
<div class="grid two">
<label>1. Branch
<input name="branch_name" list="ics204-branch-options" value="<?=h($data['branch_name'] ?? '')?>" placeholder="Select or type branch">
<datalist id="ics204-branch-options"><?php foreach($branchOptions as $opt): ?><option value="<?=h($opt)?>"></option><?php endforeach; ?></datalist>
</label>
<label>2. Division/Group/Staging
<input name="division_group" list="ics204-division-options" value="<?=h($data['division_group'] ?? '')?>" placeholder="Select or type division/group/staging">
<datalist id="ics204-division-options"><?php foreach($divisionGroupOptions as $opt): ?><option value="<?=h($opt)?>"></option><?php endforeach; ?></datalist>
</label>
</div>
<div class="grid two"><label>3. Incident Name<input readonly value="<?=h($incidentName)?>"></label><label>4. Operational Period<input readonly value="<?=h($opDates)?>"></label></div>
<fieldset><legend>5. Operational Personnel</legend><textarea name="operations_personnel" rows="3" placeholder="Division/Group Supervisor, Operations Chief, Branch Director, Staging Area Manager"><?=h($data['operations_personnel'] ?? '')?></textarea></fieldset>
<fieldset><legend>6. Resources Assigned to this Period</legend><table id="resources-table"><thead><tr><th>Resource Identifier</th><th>Leader</th><th>No. of Persons</th><th>Contact</th><th>Reporting Location / Special Equipment / Remarks</th></tr></thead><tbody>
<?php foreach($resources as $idx=>$r):?><tr><td><input name="resources[<?=$idx?>][resource_identifier]" value="<?=h($r['resource_identifier'] ?? '')?>"></td><td><input name="resources[<?=$idx?>][leader_name]" value="<?=h($r['leader_name'] ?? '')?>"></td><td><input type="number" min="0" name="resources[<?=$idx?>][number_persons]" value="<?=h((string)($r['number_persons'] ?? ''))?>"></td><td><input name="resources[<?=$idx?>][contact_info]" value="<?=h($r['contact_info'] ?? '')?>"></td><td><input name="resources[<?=$idx?>][reporting_location]" value="<?=h($r['reporting_location'] ?? '')?>"></td></tr><?php endforeach;?>
</tbody></table><button type="button" class="btn btn-secondary" onclick="addResourceRow()">Add Resource</button></fieldset>
<label>7. Work Assignments<textarea name="work_assignments" rows="5"><?=h($data['work_assignments'] ?? '')?></textarea></label>
<label>8. Special Instructions<textarea name="special_instructions" rows="4"><?=h($data['special_instructions'] ?? '')?></textarea></label>
<fieldset><legend>9. Division/Group Communications Summary</legend><p class="small">Use ICS 205/205A for detailed communications. Summary notes may be entered here.</p><textarea name="medical_plan_summary" rows="2" placeholder="Command / tactical / ground-to-air summary"><?=h($data['medical_plan_summary'] ?? '')?></textarea></fieldset>
<div class="grid two"><label>Prepared By<input name="prepared_by_name" value="<?=h($data['prepared_by_name'] ?? ($user['real_name'] ?? $user['username'] ?? ''))?>"></label><label>Prepared By Position<input name="prepared_by_position_title" value="<?=h($data['prepared_by_position_title'] ?? 'Resources Unit Leader')?>"></label></div>
<?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS204',
        'form_title' => 'ICS204 - Assignment List',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form></div>
<script>
function addResourceRow(){const tb=document.querySelector('#resources-table tbody'); const i=tb.rows.length; const tr=document.createElement('tr'); tr.innerHTML=`<td><input name="resources[${i}][resource_identifier]"></td><td><input name="resources[${i}][leader_name]"></td><td><input type="number" min="0" name="resources[${i}][number_persons]"></td><td><input name="resources[${i}][contact_info]"></td><td><input name="resources[${i}][reporting_location]"></td>`; tb.appendChild(tr);}
</script>
<?php render_footer(); ?>
