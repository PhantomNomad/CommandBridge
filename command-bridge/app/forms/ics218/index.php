<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once __DIR__ . '/../../includes/form_action_buttons.php'; ?>
<?php

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();

$pdo=db();
$incident=active_incident();
$incidentId=(int)($incident['id'] ?? 0);

if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$permissions=$incidentId>0 ? get_current_user_form_permissions('ICS218',$incidentId) : get_form_permission_defaults('ICS218');
$canView=!empty($permissions['can_view']);
$canEdit=!empty($permissions['can_edit']);
if ($incidentId<=0 || !$canView) { render_header('ICS 218'); echo '<div class="card"><p>No active incident or access denied.</p></div>'; render_footer(); exit; }

$formRecordId=get_or_create_form_record($incidentId,'ICS218','218',(int)(current_user()['id']??0));
$formRecord = get_form_record($incidentId, 'ICS218');
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS218') : ['name'=>'TBD','position_title'=>'RESL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$stmt=$pdo->prepare("SELECT id FROM form_218_headers WHERE form_record_id=? LIMIT 1");
$stmt->execute([$formRecordId]);
$headerId=(int)($stmt->fetch()['id'] ?? 0);
if ($headerId<=0 && $canEdit) {
  $u=current_user();
  $stmt=$pdo->prepare("INSERT INTO form_218_headers (form_record_id,prepared_by_name,prepared_by_position,prepared_at,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())");
  $stmt->execute([$formRecordId,(string)($u['display_name']??''),(string)($u['role_code']??''),date('Y-m-d H:i:s')]);
  $headerId=(int)$pdo->lastInsertId();
}

// Signed or approved: locked until someone revises it (includes/form_lock.php).
require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
cb_form_block_locked_post('ICS218', (int)($incidentId ?? 0), (int)($opId ?? 0), (int)($formRecordId ?? 0));

if ($_SERVER['REQUEST_METHOD']==='POST' && $canEdit) {
  if (($_POST['post_action'] ?? '')==='save_entry') {
    $id=(int)($_POST['entry_id'] ?? 0);
    $data=[trim((string)($_POST['equipment_description']??'')),trim((string)($_POST['kind']??'')),trim((string)($_POST['type']??'')),trim((string)($_POST['owner_agency']??'')),trim((string)($_POST['operator_name']??'')),trim((string)($_POST['contact_info']??'')),trim((string)($_POST['location']??'')),trim((string)($_POST['order_request_number']??'')),trim((string)($_POST['status']??'received')),trim((string)($_POST['remarks']??''))];
    if ($id>0) {
      $stmt=$pdo->prepare("UPDATE form_218_entries SET equipment_description=?,kind=?,type=?,owner_agency=?,operator_name=?,contact_info=?,location=?,order_request_number=?,status=?,remarks=?,updated_at=NOW() WHERE id=? AND header_id=?");
      $stmt->execute([...$data,$id,$headerId]);
    } else {
      $stmt=$pdo->prepare("INSERT INTO form_218_entries (header_id,equipment_description,kind,type,owner_agency,operator_name,contact_info,location,order_request_number,status,remarks,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
      $stmt->execute([$headerId,...$data]);
    }
    flash('success','ICS 218 entry saved.');
    header('Location: '.app_url('/forms/ics218/index.php')); exit;
  }
}
$entries=[];
if ($headerId>0) { $stmt=$pdo->prepare("SELECT * FROM form_218_entries WHERE header_id=? AND deleted_at IS NULL ORDER BY id"); $stmt->execute([$headerId]); $entries=$stmt->fetchAll(); }
render_header('ICS 218 - Support Vehicle/Equipment Inventory');
render_ics_form_status('ICS218', $formRecord, $preparer, $signature, ['show_print'=>false, 'show_pdf'=>true, 'pdf_url'=>'/forms/ics218/pdf.php', 'note'=>'This form remains editable while it is in draft.']);
?>
<div class="card"><h2>ICS218 - Support Vehicle/Equipment Inventory</h2>
<div class="form-actions" style="margin-bottom:10px;">
  <a class="btn" href="<?= app_url('/forms/ics218/tcard.php') ?>" target="_blank" rel="noopener">Generate ICS 219 T-Cards</a>
</div>
<p class="small">Used to track support vehicles and equipment received for the incident. Resources Unit receipt of vehicle/equipment resources from 213RR creates entries here automatically.</p>
<table class="cb-cards"><thead><tr><th>Description</th><th>Owner/Agency</th><th>Operator</th><th>Location</th><th>Order #</th><th>Status</th><th>Detailed Description</th><th>219 Card</th></tr></thead><tbody>
<?php foreach($entries as $e): ?>
<?php $kindType=trim((string)($e['kind'] ?? '').' / '.(string)($e['type'] ?? ''), ' /'); if ($kindType==='') { $kindType=(string)($e['equipment_description'] ?? ''); } ?>
<tr><td><?= e($kindType) ?></td><td><?= e($e['owner_agency']) ?></td><td><?= e($e['operator_name']) ?></td><td><?= e($e['location']) ?></td><td><?= e($e['order_request_number']) ?></td><td><?= e($e['status']) ?></td><td><?= nl2br(e($e['equipment_description'])) ?></td><td><a class="btn small" href="<?= app_url('/forms/ics218/tcard.php?entry_id='.(int)$e['id']) ?>" target="_blank" rel="noopener">T-Card</a></td></tr><?php endforeach; ?>
<?php if (!$entries): ?><tr><td colspan="8">No equipment entries yet.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($canEdit): ?><div class="card"><h3>Add Equipment</h3><form method="post" class="form-grid">
    <?= csrf_field() ?>
<input type="hidden" name="post_action" value="save_entry">
<label class="span-2">Equipment description<input name="equipment_description" required></label>
<label>Kind<input name="kind"></label><label>Type<input name="type"></label>
<label>Owner/Agency<input name="owner_agency"></label><label>Operator name<input name="operator_name"></label>
<label>Contact info<input name="contact_info"></label><label>Location<input name="location"></label>
<label>Order/request number<input name="order_request_number"></label><label>Status<select name="status"><option value="received">Received</option><option value="assigned">Assigned</option><option value="available">Available</option><option value="out_of_service">Out of service</option><option value="demob_pending">Demob pending</option><option value="released">Released</option></select></label>
<label class="span-2">Remarks<textarea name="remarks"></textarea></label>
<?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS218',
        'form_title' => 'ICS218',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form></div><?php endif; ?>
<div class="card"><h3>Help</h3><p>The T-Card buttons generate printable ICS 219-style resource status cards from the ICS 218 equipment records. Use the blank ICS 218 PDF link when you need the original template, and the generated ICS 218 PDF link when you need the current system inventory.</p><p>ICS 218 is the inventory for support vehicles and equipment. Non-equipment resources normally go to ICS 211; vehicles and equipment go to ICS 218 when received by RESL.</p><p>Use status values to show whether the item is received, assigned, demob pending, or released.</p></div>
<?php render_footer(); ?>
