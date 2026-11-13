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
$error = null;

$opId = (int)($_GET['operational_period_id'] ?? 0);
if ($opId <= 0 && $incidentId > 0) {
    $op = current_operational_period($incidentId);
    $opId = (int)($op['id'] ?? 0);
} else {
    $op = [];
    if ($opId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM operational_periods WHERE id=? AND incident_id=?");
        $stmt->execute([$opId, $incidentId]);
        $op = $stmt->fetch() ?: [];
    }
}

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS210', $incidentId) : get_form_permission_defaults('ICS210');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS210', $opId ?: null) : null;
$formRecordId = (int)($formRecord['id'] ?? 0);

if ($incidentId > 0 && !$canView) { http_response_code(403); render_header('ICS 210'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }

function ics210_user_has_role(PDO $pdo, int $incidentId, array $codes): bool {
    $u = current_user(); if (!$u) return false;
    if (!empty($u['is_sysadmin'])) return true;
    $uid = (int)($u['id'] ?? 0); if ($incidentId <= 0 || $uid <= 0) return false;
    try {
        $codes = array_values(array_unique(array_map('strtoupper', $codes)));
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM incident_positions ip INNER JOIN roles r ON r.id=ip.role_id WHERE ip.incident_id=? AND ip.user_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) AND UPPER(r.role_code) IN ($ph)");
        $st->execute(array_merge([$incidentId,$uid], $codes));
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) { return in_array(strtoupper((string)($u['role_code'] ?? '')), $codes, true); }
}
function ics210_can_submit(PDO $pdo, int $incidentId): bool { return ics210_user_has_role($pdo, $incidentId, ['IC','PSC','RESL','OSC']); }
function ics210_op_label(?array $op): string {
    if (!$op) return 'No operational period selected';
    $parts=[]; if(!empty($op['period_number'])) $parts[]='OP '.(int)$op['period_number']; if(!empty($op['period_name'])) $parts[]=(string)$op['period_name'];
    $range=trim((string)($op['op_start']??$op['start_time']??'').' to '.(string)($op['op_end']??$op['end_time']??''));
    return trim(($parts?implode(' - ',$parts).' | ':'').$range);
}

$canSubmit = ics210_can_submit($pdo, $incidentId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0) {
    $action = (string)($_POST['action'] ?? 'save');
    if (!$canEdit && !($action === 'submit' && $canSubmit)) { http_response_code(403); $error='Forbidden'; }
    else {
        try {
            $pdo->beginTransaction();
            if ($formRecordId <= 0) {
                $stmt = $pdo->prepare("INSERT INTO form_records (incident_id, operational_period_id, form_type, form_number, status, prepared_by_user_id, prepared_at) VALUES (?, ?, 'ICS210', '210', 'draft', ?, NOW())");
                $stmt->execute([$incidentId, $opId ?: null, $user['id'] ?? null]);
                $formRecordId = (int)$pdo->lastInsertId();
            } else {
                try { $pdo->prepare("UPDATE form_records SET operational_period_id=? WHERE id=?")->execute([$opId ?: null, $formRecordId]); } catch(Throwable $e) {}
            }
            if ($action === 'submit' && $canSubmit) {
                try { $pdo->prepare("UPDATE form_records SET status='submitted', submitted_by_user_id=?, submitted_at=NOW() WHERE id=?")->execute([$user['id'] ?? null, $formRecordId]); } catch(Throwable $e) { $pdo->prepare("UPDATE form_records SET status='submitted' WHERE id=?")->execute([$formRecordId]); }
            }
            $stmt=$pdo->prepare("INSERT INTO form_210_headers (form_record_id, comments, prepared_by, prepared_by_position, prepared_date_time, updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE comments=VALUES(comments), prepared_by=VALUES(prepared_by), prepared_by_position=VALUES(prepared_by_position), prepared_date_time=VALUES(prepared_date_time), updated_at=NOW()");
            $stmt->execute([$formRecordId, trim($_POST['comments'] ?? ''), trim($_POST['prepared_by'] ?? ''), trim($_POST['prepared_by_position'] ?? ''), ($_POST['prepared_date_time'] ?? '') ?: null]);

            $pdo->prepare("DELETE FROM form_210_status_rows WHERE form_record_id=?")->execute([$formRecordId]);
            $rows=$_POST['status_rows'] ?? [];
            if (is_array($rows)) {
                $ins=$pdo->prepare("INSERT INTO form_210_status_rows (form_record_id, display_order, resource_number, new_status, from_location, to_location, status_date_time) VALUES (?,?,?,?,?,?,?)");
                $line=1;
                foreach($rows as $row){
                    if(!is_array($row)) continue;
                    $resource=trim((string)($row['resource_number']??''));
                    $status=trim((string)($row['new_status']??''));
                    $from=trim((string)($row['from_location']??''));
                    $to=trim((string)($row['to_location']??''));
                    $dt=trim((string)($row['status_date_time']??''));
                    if($resource==='' && $status==='' && $from==='' && $to==='' && $dt==='') continue;
                    $ins->execute([$formRecordId,$line++,$resource,$status,$from,$to,$dt ?: null]);
                }
            }
            $pdo->commit();
            header('Location: index.php?operational_period_id='.$opId.'&'.($action==='submit'?'submitted=1':'saved=1')); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
    }
}

$data=[]; $rows=[];
if ($formRecordId > 0) {
    $stmt=$pdo->prepare("SELECT * FROM form_210_headers WHERE form_record_id=?"); $stmt->execute([$formRecordId]); $data=$stmt->fetch() ?: [];
    $st=$pdo->prepare("SELECT * FROM form_210_status_rows WHERE form_record_id=? ORDER BY display_order,id"); $st->execute([$formRecordId]); $rows=$st->fetchAll();
}
if (!$rows) $rows=[[]];
$status = (string)($formRecord['status'] ?? 'not started');

render_header('ICS 210 - Status Change');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS210') : ['name'=>'TBD','position_title'=>'Resources Unit Leader'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
render_ics_form_status('ICS210', $formRecord, $preparer, $signature, [
  'status' => $status,
  'error' => $error,
  'show_print' => false,
  'show_pdf' => false,
  'official_file' => '/assets/pdf/official/ICS210.pdf',
  'note' => $canSubmit ? 'ICS 210 is normally completed by the Resources Unit when resource status changes.' : 'Submit is limited to IC, PSC, RESL, OSC, or SysAdmin.'
]);
?>
<div class="card">
  <h2>ICS210 - Status Change</h2>
  <?php if (isset($_GET['saved'])): ?><p class="notice">Saved.</p><?php endif; ?>
  <?php if (isset($_GET['submitted'])): ?><p class="notice">ICS 210 submitted.</p><?php endif; ?>
  <p><a href="help.php">Help / Instructions</a></p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid-2">
      <label>Incident Name<input value="<?= h($incident['incident_name'] ?? $incident['name'] ?? '') ?>" readonly></label>
      <label>Operational Period<input value="<?= h(ics210_op_label($op)) ?>" readonly></label>
    </div>

    <h3>Resource Status Changes</h3>
    <table class="data-table" id="statusRowsTable">
      <thead>
        <tr>
          <th>Resource Number</th>
          <th>New Status</th>
          <th>From</th>
          <th>To</th>
          <th>Date / Time</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $i=>$r): ?>
        <tr>
          <td><input name="status_rows[<?= (int)$i ?>][resource_number]" value="<?= h($r['resource_number'] ?? '') ?>"></td>
          <td><input name="status_rows[<?= (int)$i ?>][new_status]" value="<?= h($r['new_status'] ?? '') ?>"></td>
          <td><input name="status_rows[<?= (int)$i ?>][from_location]" value="<?= h($r['from_location'] ?? '') ?>"></td>
          <td><input name="status_rows[<?= (int)$i ?>][to_location]" value="<?= h($r['to_location'] ?? '') ?>"></td>
          <td><input type="datetime-local" name="status_rows[<?= (int)$i ?>][status_date_time]" value="<?= h(isset($r['status_date_time']) ? str_replace(' ','T',substr((string)$r['status_date_time'],0,16)) : '') ?>"></td>
          <td><button type="button" onclick="this.closest('tr').remove()">Remove</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if($canEdit): ?><p><button type="button" onclick="addStatusRow()">Add Status Change Row</button></p><?php endif; ?>

    <label>Comments<textarea name="comments"><?= h($data['comments'] ?? '') ?></textarea></label>

    <div class="grid-2">
      <label>Prepared By Name<input name="prepared_by" value="<?= h($data['prepared_by'] ?? ($preparer['name'] ?? $user['real_name'] ?? $user['username'] ?? '')) ?>"></label>
      <label>Prepared By Position/Title<input name="prepared_by_position" value="<?= h($data['prepared_by_position'] ?? ($preparer['position_title'] ?? 'Resources Unit Leader')) ?>"></label>
    </div>
    <label>Date / Time Prepared<input type="datetime-local" name="prepared_date_time" value="<?= h(isset($data['prepared_date_time']) ? str_replace(' ','T',substr((string)$data['prepared_date_time'],0,16)) : '') ?>"></label>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS210',
        'form_title' => 'ICS210',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
</div>
<script>
function addStatusRow(){
  const tb=document.querySelector('#statusRowsTable tbody'); const idx=tb.children.length;
  const fields=['resource_number','new_status','from_location','to_location'];
  const tr=document.createElement('tr');
  tr.innerHTML=fields.map(f=>`<td><input name="status_rows[${idx}][${f}]" value=""></td>`).join('')+
    `<td><input type="datetime-local" name="status_rows[${idx}][status_date_time]" value=""></td>`+
    `<td><button type="button" onclick="this.closest('tr').remove()">Remove</button></td>`;
  tb.appendChild(tr);
}
</script>
<?php render_footer(); ?>
