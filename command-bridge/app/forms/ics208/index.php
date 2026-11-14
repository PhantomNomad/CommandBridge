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
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS208', $incidentId) : get_form_permission_defaults('ICS208');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
$op = $incidentId > 0 ? current_operational_period($incidentId) : null;
$opId = (int)($op['id'] ?? 0);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS208') : null;

/*
 * Earlier ICS 208 records were created without an operational_period_id on
 * form_records. Recover a matching legacy record so its saved header is shown
 * under the current operational period, then associate it for future lookups.
 */
if (!$formRecord && $incidentId > 0 && $opId > 0) {
    try {
        $stmt = $pdo->prepare(
            "SELECT fr.*
             FROM form_records fr
             INNER JOIN form_208_headers h ON h.form_record_id = fr.id
             WHERE fr.incident_id = ?
               AND (REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '') = ?
                    OR CONCAT('ICS', REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '')) = ?)
               AND fr.operational_period_id IS NULL
               AND COALESCE(fr.is_deleted, 0) = 0
               AND h.operational_period_id = ?
             ORDER BY fr.updated_at DESC, fr.id DESC
             LIMIT 1"
        );
        $stmt->execute([$incidentId, 'ICS208', 'ICS208', $opId]);
        $legacyFormRecord = $stmt->fetch();

        if ($legacyFormRecord) {
            $stmt = $pdo->prepare(
                "UPDATE form_records
                 SET operational_period_id = ?
                 WHERE id = ? AND operational_period_id IS NULL"
            );
            $stmt->execute([$opId, (int)$legacyFormRecord['id']]);

            if ($stmt->rowCount() > 0) {
                $legacyFormRecord['operational_period_id'] = $opId;
                $formRecord = $legacyFormRecord;
            }
        }
    } catch (Throwable $e) {
        // Continue with the normal empty-form path if a legacy record cannot be recovered.
    }
}

$formRecordId = (int)($formRecord['id'] ?? 0);
$error = null;

if ($incidentId > 0 && !$canView) { http_response_code(403); render_header('ICS 208 - Safety Message / Plan'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }

function ics208_user_has_role(PDO $pdo, int $incidentId, array $codes): bool {
    $u = current_user(); if (!$u) return false;
    if (!empty($u['is_sysadmin'])) return true;
    $uid = (int)($u['id'] ?? 0); if ($incidentId <= 0 || $uid <= 0) return false;
    $codes = array_values(array_unique(array_map('strtoupper', $codes)));
    try {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM incident_positions ip INNER JOIN roles r ON r.id=ip.role_id WHERE ip.incident_id=? AND ip.user_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) AND UPPER(r.role_code) IN ($ph)");
        $st->execute(array_merge([$incidentId,$uid], $codes));
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) { return in_array(strtoupper((string)($u['role_code'] ?? '')), $codes, true); }
}
function ics208_can_submit(PDO $pdo, int $incidentId): bool { return ics208_user_has_role($pdo, $incidentId, ['SO','SOFR','IC','FSC']); }
function ics208_op_label(?array $op): string {
    if (!$op) return 'No operational period selected';
    $parts=[]; if(!empty($op['period_number'])) $parts[]='OP '.(int)$op['period_number']; if(!empty($op['period_name'])) $parts[]=(string)$op['period_name'];
    $range=trim((string)($op['op_start']??$op['start_time']??'').' to '.(string)($op['op_end']??$op['end_time']??''));
    return trim(($parts?implode(' - ',$parts).' | ':'').$range);
}
$canSubmit = ics208_can_submit($pdo, $incidentId);

// Signed or approved: locked until someone revises it (includes/form_lock.php).
require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
cb_form_block_locked_post('ICS208', (int)($incidentId ?? 0), (int)($opId ?? 0), (int)($formRecordId ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0) {
    $action = (string)($_POST['action'] ?? 'save');
    if (!$canEdit && !($action === 'submit' && $canSubmit)) { http_response_code(403); $error='Forbidden'; }
    else {
        try {
            $pdo->beginTransaction();
            if ($formRecordId <= 0) {
                $stmt = $pdo->prepare("INSERT INTO form_records (incident_id, operational_period_id, form_type, form_number, status, prepared_by_user_id, prepared_at) VALUES (?, ?, 'ICS208', '208', 'draft', ?, NOW())");
                $stmt->execute([$incidentId, $opId ?: null, $user['id'] ?? null]);
                $formRecordId = (int)$pdo->lastInsertId();
            }
            if ($action === 'submit' && $canSubmit) {
                $pdo->prepare("UPDATE form_records SET status='submitted' WHERE id=?")->execute([$formRecordId]);
                require_once dirname(dirname(__DIR__)) . '/includes/signoff.php';
                signoff_record_direct_submit($formRecordId, 'ICS208', $incidentId, (int)($user['id'] ?? 0));
            }
            $stmt=$pdo->prepare("INSERT INTO form_208_headers (form_record_id, operational_period_id, safety_message, site_safety_plan_required, site_safety_plan_location, prepared_by_name, prepared_by_position_title) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE operational_period_id=VALUES(operational_period_id), safety_message=VALUES(safety_message), site_safety_plan_required=VALUES(site_safety_plan_required), site_safety_plan_location=VALUES(site_safety_plan_location), prepared_by_name=VALUES(prepared_by_name), prepared_by_position_title=VALUES(prepared_by_position_title)");
            $stmt->execute([$formRecordId, $opId ?: null, trim($_POST['safety_message'] ?? ''), !empty($_POST['site_safety_plan_required']) ? 1 : 0, trim($_POST['site_safety_plan_location'] ?? ''), trim($_POST['prepared_by_name'] ?? (($user['full_name'] ?? $user['name'] ?? '') ?: '')), trim($_POST['prepared_by_position_title'] ?? '')]);
            $pdo->commit(); header('Location: index.php?saved=1'); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $e->getMessage(); }
    }
}

$data = [];
if ($formRecordId > 0) { $stmt=$pdo->prepare("SELECT * FROM form_208_headers WHERE form_record_id=?"); $stmt->execute([$formRecordId]); $data=$stmt->fetch() ?: []; }

render_header('ICS 208 - Safety Message / Plan');
?>
<?php
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS208') : ['name'=>'TBD','position_title'=>'SOFR'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
render_ics_form_status('ICS208', $formRecord, $preparer, $signature, [
  'error' => $error,
  'show_print' => false,
  'show_pdf' => $formRecordId > 0,
  'pdf_url' => '/forms/ics208/pdf.php',
  'note' => 'This form remains editable while it is in draft.'
]);
?>
<div class="card">
  <h2>ICS208 - Safety Message / Plan</h2>
  <?php if (isset($_GET['saved'])): ?><p class="notice">Saved.</p><?php endif; ?>
  <p><a href="help.php">Help / Instructions</a></p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid two">
      <label>1. Incident Name<input value="<?= h($incident['incident_name'] ?? $incident['name'] ?? '') ?>" readonly></label>
      <label>2. Operational Period<input value="<?= h(ics208_op_label($op)) ?>" readonly></label>
    </div>
    <label>3. Safety Message / Plan
      <textarea name="safety_message" rows="18" style="min-height:360px" <?= $canEdit ? '' : 'readonly' ?>><?= h($data['safety_message'] ?? '') ?></textarea>
    </label>
    <div class="grid two">
      <label><input type="checkbox" name="site_safety_plan_required" value="1" <?= !empty($data['site_safety_plan_required']) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> Site Safety Plan required / attached / available</label>
      <label>Site Safety Plan Location<input name="site_safety_plan_location" value="<?= h($data['site_safety_plan_location'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
    </div>
    <div class="grid two">
      <label>4. Prepared By / Safety Officer<input name="prepared_by_name" value="<?= h($data['prepared_by_name'] ?? ($user['full_name'] ?? $user['name'] ?? '')) ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
      <label>Position / Title<input name="prepared_by_position_title" value="<?= h($data['prepared_by_position_title'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
    </div>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS208',
        'form_title' => 'ICS208',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
</div>
<?php render_footer(); ?>
