<?php
declare(strict_types=1);
/* __ICS202_INLINE_PRINT_ROUTE__ */
if (($_GET['view'] ?? '') === 'print') {
    $_GET['inline'] = '1';
    require __DIR__ . '/download_202_pdf.php';
    exit;
}
/* __END_ICS202_INLINE_PRINT_ROUTE__ */

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
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS202', $incidentId) : get_form_permission_defaults('ICS202');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
$formRecord = $incidentId > 0 ? ics202_get_form_record($pdo, $incidentId) : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$user = current_user();
$canApprove = current_user_is_ic_or_tsc($pdo, $incidentId);
$error = null;

function first_table(PDO $pdo, array $names): ?string {
    foreach ($names as $n) {
        try { $st=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($n)); if ($st && $st->fetchColumn()) return $n; } catch (Throwable $e) {}
    }
    return null;
}
function table_cols(PDO $pdo, string $table): array {
    try { return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field'); } catch (Throwable $e) { return []; }
}
function pick_col(array $cols, array $candidates): ?string { foreach ($candidates as $c) if (in_array($c,$cols,true)) return $c; return null; }

function current_user_is_ic_or_tsc(PDO $pdo, int $incidentId): bool {
    $user = current_user();
    if (!$user) return false;
    if (!empty($user['is_tsc_admin'])) return true;
    $userId = (int)($user['id'] ?? 0);
    if ($incidentId <= 0 || $userId <= 0) return false;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM incident_positions ip
            INNER JOIN roles r ON r.id = ip.role_id
            WHERE ip.incident_id = ?
              AND ip.user_id = ?
              AND ip.deleted_at IS NULL
              AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
              AND r.role_code = 'IC'
        ");
        $stmt->execute([$incidentId, $userId]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return (($user['role_code'] ?? '') === 'IC');
    }
}


function table_exists(PDO $pdo, string $table): bool {
    try { $st = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($st && $st->fetchColumn()); } catch (Throwable $e) { return false; }
}
function ics202_get_form_record(PDO $pdo, int $incidentId): ?array {
    if ($incidentId <= 0 || !table_exists($pdo, 'form_records')) { return null; }
    try {
        $stmt = $pdo->prepare("SELECT * FROM form_records WHERE incident_id=? AND form_type='ICS202' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$incidentId]);
        $record = $stmt->fetch();
        return is_array($record) ? $record : null;
    } catch (Throwable $e) {
        return null;
    }
}
function form_table_has_data_for_op(PDO $pdo, int $incidentId, ?int $opId, string $formType, string $table, ?string $opColumn = 'operational_period_id'): bool {
    if ($incidentId <= 0 || !table_exists($pdo, $table) || !table_exists($pdo, 'form_records')) return false;
    try {
        $cols = table_cols($pdo, $table);
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

function get_ics202_header_id(PDO $pdo, int $formRecordId): int {
    if ($formRecordId <= 0 || !table_exists($pdo, 'form_202_headers')) return 0;
    $st = $pdo->prepare("SELECT id FROM form_202_headers WHERE form_record_id=? LIMIT 1");
    $st->execute([$formRecordId]);
    return (int)($st->fetchColumn() ?: 0);
}
function get_ics202_attachments(PDO $pdo, int $headerId): array {
    if ($headerId <= 0 || !table_exists($pdo, 'form_202_attachments')) return [];
    $st = $pdo->prepare("SELECT * FROM form_202_attachments WHERE form_202_header_id=? ORDER BY created_at ASC, id ASC");
    $st->execute([$headerId]);
    return $st->fetchAll();
}
function ics202_attachment_url(int $id): string { return app_url('/forms/ics202/file.php?id='.$id); }

$opTable = first_table($pdo, ['operational_periods','incident_operational_periods']);
$ops = [];
if ($incidentId && $opTable) {
    $cols = table_cols($pdo,$opTable);
    $id = pick_col($cols,['id']);
    $name = pick_col($cols,['name','period_name','operational_period_name']);
    $start = pick_col($cols,['start_time','start_datetime','period_start','from_datetime','op_start']);
    $end = pick_col($cols,['end_time','end_datetime','period_end','to_datetime','op_end']);
    $inc = pick_col($cols,['incident_id']);
    $select = "`$id` AS id".($name?", `$name` AS name":"").($start?", `$start` AS start_time":"").($end?", `$end` AS end_time":"");
    $sql = "SELECT $select FROM `$opTable`".($inc?" WHERE `$inc`=?":"")." ORDER BY ".($start?"`$start`":"`$id`");
    $st=$pdo->prepare($sql); $st->execute($inc?[$incidentId]:[]); $ops=$st->fetchAll();
}

if ($incidentId > 0 && !$canView) { http_response_code(403); render_header('ICS 202'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0 && ($_POST['action'] ?? '') === 'approve_ics202') {
    if (!$canApprove) { http_response_code(403); render_header('ICS 202'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }
    try {
        if ($formRecordId <= 0) { throw new RuntimeException('Save the ICS 202 before approving it.'); }
        if (!table_exists($pdo, 'form_202_headers')) { throw new RuntimeException('ICS 202 header table is missing.'); }

        $stOp = $pdo->prepare("SELECT operational_period_id FROM form_202_headers WHERE form_record_id=? LIMIT 1");
        $stOp->execute([$formRecordId]);
        $approvalOpId = (int)($stOp->fetchColumn() ?: 0);

        $approvalIncludes = [
            'ICS 203' => ics202_has_assigned_incident_commander($pdo, $incidentId),
            'ICS 204' => form_table_has_data_for_op($pdo, $incidentId, $approvalOpId ?: null, 'ICS204', 'form_204_headers'),
            'ICS 205' => form_table_has_data_for_op($pdo, $incidentId, $approvalOpId ?: null, 'ICS205', 'form_205_headers'),
            'ICS 205A' => (form_table_has_data_for_op($pdo, $incidentId, $approvalOpId ?: null, 'ICS205A', 'form_205a_headers') || form_table_has_data_for_op($pdo, $incidentId, null, 'ICS205A', 'form_205a_headers', null)),
            'ICS 206' => form_table_has_data_for_op($pdo, $incidentId, $approvalOpId ?: null, 'ICS206', 'form_206_headers'),
            'ICS 208' => form_table_has_data_for_op($pdo, $incidentId, $approvalOpId ?: null, 'ICS208', 'form_208_headers'),
        ];
        $approvalMissing = array_keys(array_filter($approvalIncludes, static fn($detected) => !$detected));
        if ($approvalMissing) {
            throw new RuntimeException('ICS 202 cannot be approved until these IAP forms have data for this incident/operational period: '.implode(', ', $approvalMissing).'.');
        }

        $approvedName = (string)($user['display_name'] ?? $user['real_name'] ?? $user['username'] ?? '');
        $stmt = $pdo->prepare("UPDATE form_202_headers SET approved_by_ic_user_id=?, approved_by_ic_name=?, approved_at=NOW() WHERE form_record_id=?");
        $stmt->execute([(int)($user['id'] ?? 0), $approvedName, $formRecordId]);
        try { $pdo->prepare("UPDATE form_records SET status='approved', updated_at=NOW() WHERE id=?")->execute([$formRecordId]); } catch (Throwable $e) {}
        audit_log((int)($user['id'] ?? 0), 'approve', 'ICS202', $formRecordId, 'ICS 202 approved');
        header('Location: index.php?approved=1'); exit;
    } catch (Throwable $e) { $error=$e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $incidentId > 0) {
    try {
        $pdo->beginTransaction();
        if ($formRecordId <= 0) {
            $stmt = $pdo->prepare("INSERT INTO form_records (incident_id, form_type, form_number, status, prepared_by_user_id, prepared_at) VALUES (?, 'ICS202', '202', 'draft', ?, NOW())");
            $stmt->execute([$incidentId, $user['id'] ?? null]);
            $formRecordId = (int)$pdo->lastInsertId();
        }
        $sql = "INSERT INTO form_202_headers
        (form_record_id, operational_period_id, objectives, operational_period_command_emphasis, general_situational_awareness,
         site_safety_plan_required, site_safety_plan_location, iap_include_ics203, iap_include_ics204, iap_include_ics205,
         iap_include_ics205a, iap_include_ics206, iap_include_ics208, prepared_by_user_id, prepared_by_name, prepared_by_position_title, prepared_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE operational_period_id=VALUES(operational_period_id), objectives=VALUES(objectives),
        operational_period_command_emphasis=VALUES(operational_period_command_emphasis), general_situational_awareness=VALUES(general_situational_awareness),
        site_safety_plan_required=VALUES(site_safety_plan_required), site_safety_plan_location=VALUES(site_safety_plan_location),
        iap_include_ics203=VALUES(iap_include_ics203), iap_include_ics204=VALUES(iap_include_ics204), iap_include_ics205=VALUES(iap_include_ics205),
        iap_include_ics205a=VALUES(iap_include_ics205a), iap_include_ics206=VALUES(iap_include_ics206), iap_include_ics208=VALUES(iap_include_ics208),
        prepared_by_user_id=VALUES(prepared_by_user_id),
        prepared_by_name=VALUES(prepared_by_name), prepared_by_position_title=VALUES(prepared_by_position_title), prepared_at=VALUES(prepared_at)";
        $stmt=$pdo->prepare($sql);
        $stmt->execute([
            $formRecordId, ($_POST['operational_period_id'] ?: null), trim($_POST['objectives'] ?? ''),
            trim($_POST['operational_period_command_emphasis'] ?? ''), trim($_POST['general_situational_awareness'] ?? ''),
            isset($_POST['site_safety_plan_required'])?1:0, trim($_POST['site_safety_plan_location'] ?? ''),
            isset($_POST['iap_include_ics203'])?1:0, isset($_POST['iap_include_ics204'])?1:0, isset($_POST['iap_include_ics205'])?1:0,
            isset($_POST['iap_include_ics205a'])?1:0, isset($_POST['iap_include_ics206'])?1:0, isset($_POST['iap_include_ics208'])?1:0,
            $user['id'] ?? null, trim($_POST['prepared_by_name'] ?? ($user['name'] ?? '')), trim($_POST['prepared_by_position_title'] ?? '')
        ]);
        $pdo->commit(); header('Location: index.php?saved=1'); exit;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
}
$data=[];
if ($formRecordId>0) { $st=$pdo->prepare("SELECT * FROM form_202_headers WHERE form_record_id=?"); $st->execute([$formRecordId]); $data=$st->fetch()?:[]; }
$selectedOpId = (int)($data['operational_period_id'] ?? ($_GET['op_id'] ?? 0));
if (!$selectedOpId) {
    $activeOp = current_operational_period($incidentId);
    $selectedOpId = (int)($activeOp['id'] ?? 0);
}
if (!$selectedOpId && count($ops) === 1) { $selectedOpId = (int)$ops[0]['id']; }
$autoIncludes = [
    'ics203' => ics202_has_assigned_incident_commander($pdo, $incidentId),
    'ics204' => form_table_has_data_for_op($pdo, $incidentId, $selectedOpId ?: null, 'ICS204', 'form_204_headers'),
    'ics205' => form_table_has_data_for_op($pdo, $incidentId, $selectedOpId ?: null, 'ICS205', 'form_205_headers'),
    'ics205a' => (form_table_has_data_for_op($pdo, $incidentId, $selectedOpId ?: null, 'ICS205A', 'form_205a_headers') || form_table_has_data_for_op($pdo, $incidentId, null, 'ICS205A', 'form_205a_headers', null)),
    'ics206' => form_table_has_data_for_op($pdo, $incidentId, $selectedOpId ?: null, 'ICS206', 'form_206_headers'),
    'ics208' => form_table_has_data_for_op($pdo, $incidentId, $selectedOpId ?: null, 'ICS208', 'form_208_headers'),
];
foreach ($autoIncludes as $k => $v) { if ($v) { $data['iap_include_'.$k] = 1; } }
$requiredIapForms = [
    'ics203' => 'ICS 203',
    'ics204' => 'ICS 204',
    'ics205' => 'ICS 205',
    'ics205a' => 'ICS 205A',
    'ics206' => 'ICS 206',
    'ics208' => 'ICS 208',
];
$missingRequiredIapForms = [];
foreach ($requiredIapForms as $key => $label) {
    if (empty($autoIncludes[$key])) {
        $missingRequiredIapForms[$key] = $label;
    }
}
$allRequiredIapFormsDetected = empty($missingRequiredIapForms);
$header202Id = get_ics202_header_id($pdo, $formRecordId);
$attachments = get_ics202_attachments($pdo, $header202Id);

function checked($v){ return !empty($v) ? 'checked' : ''; }
$isPrintView = (string)($_GET['view'] ?? '') === 'print';
render_header($isPrintView ? 'ICS 202 - Incident Objectives Print View' : 'ICS 202 - Incident Objectives');
?>
<div class="card no-print">
<h2>ICS202 - Incident Objectives</h2>
<?php if(isset($_GET['saved'])):?><p class="notice">Saved.</p><?php endif; ?><?php if(isset($_GET['approved'])):?><p class="notice">Approved.</p><?php endif; ?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
<p><strong>Status:</strong> <?=h(ucfirst((string)($formRecord['status'] ?? 'draft')))?></p>
<?php if (!empty($data['approved_at'])): ?>
  <p><strong>Approved By:</strong> <?=h($data['approved_by_ic_name'] ?? '')?> on <?=h($data['approved_at'] ?? '')?></p>
<?php else: ?>
  <p class="small">ICS 202 remains in draft until the Incident Commander or Technical Services Coordinator/Admin approves it. Approval is locked until the required IAP forms are detected.</p>
<?php endif; ?>
<p class="small">Attachments accepted here: BMP, JPG, JPEG, PNG, PDF, and TXT.</p>
</div>
<div class="card">
<h2>ICS202 - Incident Objectives</h2>
<p><a href="help.php">Help / Instructions</a></p>
<form method="post" class="ics202-form">
    <?= csrf_field() ?>
<input type="hidden" name="action" value="save_ics202">
<label>1. Incident Name<input value="<?=h($incident['incident_name'] ?? $incident['name'] ?? '')?>" readonly></label>
<label>2. Operational Period
<select name="operational_period_id" required <?= $canEdit?'':'disabled'?>>
<option value="">Select operational period...</option>
<?php foreach($ops as $op): $label=trim(($op['name']??'').' '.($op['start_time']??'').' - '.($op['end_time']??'')); ?>
<option value="<?=h((string)$op['id'])?>" <?=((string)($selectedOpId??'')===(string)$op['id'])?'selected':''?>><?=h($label ?: ('Operational Period '.$op['id']))?></option>
<?php endforeach; ?>
</select></label>
<label>3. Objectives<textarea name="objectives" <?= $canEdit?'':'readonly'?>><?=h($data['objectives'] ?? '')?></textarea></label>
<label>4. Operational Period Command Emphasis<textarea name="operational_period_command_emphasis" <?= $canEdit?'':'readonly'?>><?=h($data['operational_period_command_emphasis'] ?? '')?></textarea></label>
<label>5. General Situational Awareness<textarea name="general_situational_awareness" <?= $canEdit?'':'readonly'?>><?=h($data['general_situational_awareness'] ?? '')?></textarea></label>
<fieldset><legend>Site Safety Plan</legend>
<label><input type="checkbox" name="site_safety_plan_required" <?=checked($data['site_safety_plan_required']??0)?>> Site Safety Plan required</label>
<label>If yes, approved Site Safety Plan(s) located at<input name="site_safety_plan_location" value="<?=h($data['site_safety_plan_location'] ?? '')?>"></label>
</fieldset>
<fieldset><legend>6. Incident Action Plan attachments/forms included</legend>
<?php foreach(['ics203'=>'ICS 203','ics204'=>'ICS 204','ics205'=>'ICS 205','ics205a'=>'ICS 205A','ics206'=>'ICS 206','ics208'=>'ICS 208'] as $k=>$lbl): ?>
<label><input type="checkbox" name="iap_include_<?=$k?>" <?=checked($data['iap_include_'.$k]??0)?>> <?=h($lbl)?><?php if(!empty($autoIncludes[$k])): ?> <span class="small">(detected)</span><?php endif; ?></label>
<?php endforeach; ?>
</fieldset>
<fieldset><legend>7. Prepared By</legend>
<label>Name<input name="prepared_by_name" value="<?=h($data['prepared_by_name'] ?? ($user['name'] ?? ''))?>"></label>
<label>ICS Position/Title<input name="prepared_by_position_title" value="<?=h($data['prepared_by_position_title'] ?? '')?>"></label>
</fieldset>
<fieldset><legend>8. Approved By Incident Commander</legend>
<?php if (!empty($data['approved_at'])): ?>
  <p class="notice">Approved by <?=h($data['approved_by_ic_name'] ?? '')?> at <?=h($data['approved_at'] ?? '')?>.</p>
<?php else: ?>
  <p>Not approved yet.</p>
<?php endif; ?>
<?php if ($header202Id <= 0): ?>
  <p class="small">Save the ICS 202 before approval.</p>
<?php elseif (!$allRequiredIapFormsDetected): ?>
  <p class="small">Approval is locked until all required IAP forms have data for this incident and operational period.</p>
  <p class="small">Missing: <?=h(implode(', ', $missingRequiredIapForms))?></p>
<?php elseif ($canApprove): ?>
  <p class="small">Use the Form Actions Approve / Submit button below.</p>
<?php else: ?>
  <p class="small">Only the assigned Incident Commander or Technical Services Coordinator/Admin can approve this form.</p>
<?php endif; ?>
</fieldset>
<?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $selectedOpId ?: ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS202',
        'form_title' => 'ICS202',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),
        'save_label' => 'Save Draft',
        'show_print' => false,
        'show_download' => false,
        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
</div>
<div class="card">
<h2>Other Attachments</h2>
<?php if ($header202Id <= 0): ?>
  <p>Save the ICS 202 draft before adding attachments.</p>
<?php else: ?>
  <?php if ($canEdit): ?>
  <form method="post" action="<?=h(app_url('/forms/ics202/attachment_upload.php'))?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="file" name="attachments[]" multiple accept=".bmp,.jpg,.jpeg,.png,.pdf,.txt,image/bmp,image/jpeg,image/png,application/pdf,text/plain" required>
    <p class="small">Accepted: BMP, JPG, JPEG, PNG, PDF, and TXT. Maximum file size per upload: 15 MB.</p>
    <button type="submit">Upload Attachment(s)</button>
  </form>
  <?php endif; ?>
  <?php if (!$attachments): ?>
    <p style="margin-top:16px;">No attachments uploaded yet.</p>
  <?php else: ?>
    <table style="margin-top:16px;">
      <tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Open</th><?php if($canEdit): ?><th>Action</th><?php endif; ?></tr>
      <?php foreach($attachments as $attachment): ?>
      <tr>
        <td><?=h($attachment['original_name'])?></td>
        <td><?=h(extension_to_badge_label((string)$attachment['file_ext']))?></td>
        <td><?=h(format_bytes_human((int)$attachment['file_size']))?></td>
        <td><?=h((string)$attachment['created_at'])?></td>
        <td><a href="<?=h(ics202_attachment_url((int)$attachment['id']))?>" target="_blank">Open</a></td>
        <?php if($canEdit): ?><td><form method="post" action="<?=h(app_url('/forms/ics202/attachment_delete.php'))?>" onsubmit="return confirm('Remove this attachment?');"><?= csrf_field() ?><input type="hidden" name="attachment_id" value="<?=h((string)$attachment['id'])?>"><button type="submit">Delete</button></form></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php render_footer(); ?>