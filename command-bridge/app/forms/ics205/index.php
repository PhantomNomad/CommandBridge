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
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS205', $incidentId) : get_form_permission_defaults('ICS205');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
$op = $incidentId > 0 ? current_operational_period($incidentId) : null;
$opId = (int)($op['id'] ?? 0);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS205') : null;

/*
 * Earlier ICS 205 records were created without form_records.operational_period_id.
 * Associate a matching legacy record with the active operational period so its
 * saved radio-channel rows remain visible.
 */
if (!$formRecord && $incidentId > 0 && $opId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT fr.* FROM form_records fr INNER JOIN form_205_headers h ON h.form_record_id=fr.id WHERE fr.incident_id=? AND (REPLACE(REPLACE(UPPER(fr.form_type),' ',''),'-','') = ? OR CONCAT('ICS',REPLACE(REPLACE(UPPER(fr.form_type),' ',''),'-','')) = ?) AND fr.operational_period_id IS NULL AND fr.is_deleted=0 AND h.operational_period_id=? ORDER BY fr.updated_at DESC, fr.id DESC LIMIT 1");
        $stmt->execute([$incidentId, 'ICS205', 'ICS205', $opId]);
        $legacyFormRecord = $stmt->fetch() ?: null;
        if ($legacyFormRecord) {
            $pdo->prepare("UPDATE form_records SET operational_period_id=? WHERE id=? AND operational_period_id IS NULL")->execute([$opId, (int)$legacyFormRecord['id']]);
            $legacyFormRecord['operational_period_id'] = $opId;
            $formRecord = $legacyFormRecord;
        }
    } catch (Throwable $e) {
        // Retain normal empty-form behaviour if an older database cannot be updated.
    }
}
$formRecordId = (int)($formRecord['id'] ?? 0);
$error = null;
$user = current_user();

if ($incidentId > 0 && !$canView) { http_response_code(403); render_header('ICS 205'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }

function ics205_user_has_role(PDO $pdo, int $incidentId, array $codes): bool {
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
function ics205_can_submit(PDO $pdo, int $incidentId): bool { return ics205_user_has_role($pdo, $incidentId, ['IC','COML']); }
function ics205_op_label(?array $op): string {
    if (!$op) return 'No operational period selected';
    $parts=[]; if(!empty($op['period_number'])) $parts[]='OP '.(int)$op['period_number']; if(!empty($op['period_name'])) $parts[]=(string)$op['period_name'];
    $range=trim((string)($op['op_start']??$op['start_time']??'').' to '.(string)($op['op_end']??$op['end_time']??''));
    return trim(($parts?implode(' - ',$parts).' | ':'').$range);
}

$canSubmit = ics205_can_submit($pdo, $incidentId);

// Signed or approved: locked until someone revises it (includes/form_lock.php).
require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
cb_form_block_locked_post('ICS205', (int)($incidentId ?? 0), (int)($opId ?? 0), (int)($formRecordId ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0) {
    $action = (string)($_POST['action'] ?? 'save');
    if (!$canEdit && !($action === 'submit' && $canSubmit)) { http_response_code(403); $error='Forbidden'; }
    else {
        try {
            $pdo->beginTransaction();
            if ($formRecordId <= 0) {
                $stmt = $pdo->prepare("INSERT INTO form_records (incident_id, operational_period_id, form_type, form_number, status, prepared_by_user_id, prepared_at) VALUES (?, ?, 'ICS205', '205', 'draft', ?, NOW())");
                $stmt->execute([$incidentId, $opId ?: null, $user['id'] ?? null]);
                $formRecordId = (int)$pdo->lastInsertId();
            }
            $statusSql = $action === 'submit' && $canSubmit ? ", status='submitted', submitted_by_user_id=?, submitted_at=NOW()" : '';
            if ($statusSql) {
                try { $pdo->prepare("UPDATE form_records SET status='submitted' WHERE id=?")->execute([$formRecordId]); } catch(Throwable $e) {}
            }
            $stmt=$pdo->prepare("INSERT INTO form_205_headers (form_record_id, operational_period_id, special_instructions, prepared_by_name, prepared_by_position_title) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE operational_period_id=VALUES(operational_period_id), special_instructions=VALUES(special_instructions), prepared_by_name=VALUES(prepared_by_name), prepared_by_position_title=VALUES(prepared_by_position_title)");
            $stmt->execute([$formRecordId, $opId ?: null, trim($_POST['special_instructions'] ?? ''), trim($_POST['prepared_by_name'] ?? ''), trim($_POST['prepared_by_position_title'] ?? '')]);
            $headerId = (int)$pdo->lastInsertId();
            if ($headerId <= 0) { $s=$pdo->prepare("SELECT id FROM form_205_headers WHERE form_record_id=?"); $s->execute([$formRecordId]); $headerId=(int)$s->fetchColumn(); }
            $pdo->prepare("DELETE FROM form_205_channels WHERE form_205_id=?")->execute([$headerId]);
            $fields=['system_type','channel','function','frequency_tone','assignment','remarks'];
            $rows=$_POST['channels'] ?? [];
            if (is_array($rows)) {
                $ins=$pdo->prepare("INSERT INTO form_205_channels (`form_205_id`,`sort_order`,`system_type`,`channel`,`function`,`frequency_tone`,`assignment`,`remarks`) VALUES (?,?,?,?,?,?,?,?)");
                $line=1;
                foreach($rows as $row){
                    if(!is_array($row)) continue;
                    $vals=[]; $nonblank=false;
                    foreach($fields as $f){ $v=trim((string)($row[$f]??'')); $vals[]=$v; if($v!=='') $nonblank=true; }
                    if(!$nonblank) continue;
                    $ins->execute(array_merge([$headerId,$line++],$vals));
                }
            }
            $pdo->commit();
            header('Location: index.php?'.($action==='submit'?'submitted=1':'saved=1')); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
    }
}
$data=[]; $channels=[];
if ($formRecordId > 0) {
    $stmt=$pdo->prepare("SELECT * FROM form_205_headers WHERE form_record_id=?"); $stmt->execute([$formRecordId]); $data=$stmt->fetch() ?: [];
    if($data){ $st=$pdo->prepare("SELECT * FROM form_205_channels WHERE form_205_id=? ORDER BY sort_order,id"); $st->execute([(int)$data['id']]); $channels=$st->fetchAll(); }
}
if (!$channels) $channels=[[]];
$status = (string)($formRecord['status'] ?? 'not started');

render_header('ICS 205 - Radio Communications Plan');
?>
<?php
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS205') : ['name'=>'TBD','position_title'=>'COML'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
render_ics_form_status('ICS205', $formRecord, $preparer, $signature, [
  'status' => $status,
  'error' => $error,
  'show_print' => false,
  'show_pdf' => $formRecordId > 0,
  'pdf_url' => '/forms/ics205/pdf.php' . ($incidentId > 0
      ? '?incident_id=' . rawurlencode((string)$incidentId) . ($opId > 0 ? '&op_id=' . rawurlencode((string)$opId) : '')
      : ''),
  'note' => $canSubmit ? 'This form remains editable while it is in draft.' : 'Submit is limited to IC, COML, or SysAdmin.'
]);
?>
<div class="card">
  <h2>ICS205 - Radio Communications Plan</h2>
  <?php if (isset($_GET['saved'])): ?><p class="notice">Saved.</p><?php endif; ?>
  <?php if (isset($_GET['submitted'])): ?><p class="notice">ICS 205 submitted.</p><?php endif; ?>
  <p><a href="help.php">Help / Instructions</a></p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid-2">
      <label>Incident Name<input value="<?= h($incident['incident_name'] ?? $incident['name'] ?? '') ?>" readonly></label>
      <label>Operational Period<input value="<?= h(ics205_op_label($op)) ?>" readonly></label>
    </div>
    <h3>4. Basic Radio Channel Utilization</h3>
    <table class="data-table cb-cards" id="channelsTable">
      <thead><tr><th>System/Type</th><th>Channel</th><th>Function</th><th>Frequency/Tone</th><th>Assignment</th><th>Remarks</th><th></th></tr></thead>
      <tbody>
      <?php foreach($channels as $i=>$r): ?>
        <tr>
        <?php foreach(['system_type','channel','function','frequency_tone','assignment','remarks'] as $f): ?>
          <td><input name="channels[<?= (int)$i ?>][<?= h($f) ?>]" value="<?= h($r[$f] ?? '') ?>"></td>
        <?php endforeach; ?>
          <td><button type="button" onclick="this.closest('tr').remove()">Remove</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if($canEdit): ?><p><button type="button" onclick="addChannelRow()">Add Channel Row</button></p><?php endif; ?>
    <label>Special Instructions<textarea name="special_instructions"><?= h($data['special_instructions'] ?? '') ?></textarea></label>
    <div class="grid-2">
      <label>Prepared By Name<input name="prepared_by_name" value="<?= h($data['prepared_by_name'] ?? ($user['real_name'] ?? $user['username'] ?? '')) ?>"></label>
      <label>Prepared By Position/Title<input name="prepared_by_position_title" value="<?= h($data['prepared_by_position_title'] ?? 'Communications Unit Leader') ?>"></label>
    </div>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS205',
        'form_title' => 'ICS205',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),
        'show_print' => false,
        'show_download' => false,
        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
</div>
<script>
function addChannelRow(){
  const tb=document.querySelector('#channelsTable tbody'); const idx=tb.children.length;
  const fields=['system_type','channel','function','frequency_tone','assignment','remarks'];
  const tr=document.createElement('tr');
  tr.innerHTML=fields.map(f=>`<td><input name="channels[${idx}][${f}]" value=""></td>`).join('')+`<td><button type="button" onclick="this.closest('tr').remove()">Remove</button></td>`;
  tb.appendChild(tr);
}
</script>
<?php render_footer(); ?>
