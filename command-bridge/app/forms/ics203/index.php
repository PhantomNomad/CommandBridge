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
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS203', $incidentId) : get_form_permission_defaults('ICS203');
$canView = !empty($permissions['can_view']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS203') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$error = null;
$user = current_user();

if ($incidentId > 0 && !$canView) { http_response_code(403); render_header('ICS 203'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }


function ics203_submitter_role_codes(): array {
    // ICS 203 is normally prepared/maintained by the Resources Unit Leader under Planning.
    // Allow IC and SysAdmin as a controlling authority, and RESL as the form owner/preparer.
    return ['IC', 'RESL'];
}

function current_user_can_submit_ics203(PDO $pdo, int $incidentId): bool {
    $user = current_user();
    if (!$user) return false;
    if (!empty($user['is_sysadmin'])) return true;
    $userId = (int)($user['id'] ?? 0);
    if ($incidentId <= 0 || $userId <= 0) return false;
    try {
        $allowed = ics203_submitter_role_codes();
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $params = array_merge([$incidentId, $userId], $allowed);
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM incident_positions ip
            INNER JOIN roles r ON r.id = ip.role_id
            WHERE ip.incident_id = ?
              AND ip.user_id = ?
              AND ip.deleted_at IS NULL
              AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
              AND UPPER(r.role_code) IN ($placeholders)
        ");
        $st->execute($params);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return in_array(strtoupper((string)($user['role_code'] ?? '')), ics203_submitter_role_codes(), true);
    }
}


function ics203_position_catalog(): array {
    return [
        ['Command Staff','Incident Commander',['IC']],
        ['Command Staff','Safety Officer',['SOFR','SO']],
        ['Command Staff','Information Officer',['PIO','IO']],
        ['Command Staff','Liaison Officer',['LOFR','LO']],
        ['Operations Section','Operations Section Chief',['OSC','OPS','OSCHIEF']],
        ['Operations Section','Staging Area Manager',['STAM','SAM']],
        ['Operations Section','Branch Director',['BD','BDIR']],
        ['Operations Section','Division/Group Supervisor',['DIVS','DGS','DGSUP']],
        ['Operations Section','Air Operations Branch Director',['AOBD']],
        ['Planning Section','Planning Section Chief',['PSC','PLAN','PSCHIEF']],
        ['Planning Section','Resources Unit Leader',['RESL','RUL']],
        ['Planning Section','Situation Unit Leader',['SITL']],
        ['Planning Section','Documentation Unit Leader',['DOCL','DUL']],
        ['Planning Section','Demobilization Unit Leader',['DMOB','DEMOB']],
        ['Planning Section','Technical Specialist',['THSP','TS']],
        ['Logistics Section','Logistics Section Chief',['LSC','LOG','LSCHIEF']],
        ['Logistics Section','Communication Unit Leader',['COML']],
        ['Logistics Section','Medical Unit Leader',['MEDL','MUL']],
        ['Logistics Section','Food Unit Leader',['FOOD']],
        ['Logistics Section','Supply Unit Leader',['SPUL','SUL']],
        ['Logistics Section','Facilities Unit Leader',['FUL']],
        ['Logistics Section','Ground Support Unit Leader',['GSUL','GUL']],
        ['Finance / Administration Section','Finance/Admin Section Chief',['FSC','FAC','FIN','FAS']],
        ['Finance / Administration Section','Time Unit Leader',['TIME','TUL']],
        ['Finance / Administration Section','Procurement Unit Leader',['PROC','PUL']],
        ['Finance / Administration Section','Compensation/Claims Unit Leader',['COMP','CCUL','CCL']],
        ['Finance / Administration Section','Cost Unit Leader',['COST']],
    ];
}

function ics203_rows(PDO $pdo, int $incidentId): array {
    $positions = ics203_position_catalog();
    $assigned = [];
    try {
        $st = $pdo->prepare("SELECT r.role_code, u.id AS user_id, u.real_name, u.username
            FROM incident_positions ip
            INNER JOIN roles r ON r.id=ip.role_id
            LEFT JOIN users u ON u.id=ip.user_id AND u.deleted_at IS NULL
            WHERE ip.incident_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())");
        $st->execute([$incidentId]);
        foreach ($st->fetchAll() as $r) {
            $code = strtoupper(trim((string)$r['role_code']));
            if ($code === '') continue;
            $assigned[$code] = ['user_id'=>(int)($r['user_id'] ?? 0), 'name'=>(string)($r['real_name'] ?: $r['username'] ?: '')];
        }
    } catch (Throwable $e) {}
    $rows = [];
    $i = 1;
    foreach ($positions as $p) {
        $a = ['user_id'=>null,'name'=>''];
        foreach ((array)$p[2] as $code) {
            $code = strtoupper((string)$code);
            if (isset($assigned[$code])) { $a = $assigned[$code]; break; }
        }
        $rows[] = ['section_name'=>$p[0], 'position_title'=>$p[1], 'assigned_name'=>$a['name'], 'user_id'=>$a['user_id'], 'sort_order'=>$i++];
    }
    return $rows;
}
function ics203_has_ic(PDO $pdo, int $incidentId): bool {
    foreach (ics203_rows($pdo, $incidentId) as $r) {
        if ($r['position_title'] === 'Incident Commander' && trim((string)$r['assigned_name']) !== '') return true;
    }
    return false;
}
function ics203_ensure_record(PDO $pdo, int $incidentId, array $rows): int {
    $fr = get_form_record($incidentId, 'ICS203');
    $id = (int)($fr['id'] ?? 0);
    if ($id <= 0) {
        $st = $pdo->prepare("INSERT INTO form_records (incident_id, form_type, form_number, status, prepared_by_user_id, prepared_at) VALUES (?, 'ICS203', '203', 'draft', ?, NOW())");
        $st->execute([$incidentId, current_user()['id'] ?? null]);
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("DELETE FROM form_203_assignments WHERE form_record_id=?")->execute([$id]);
    $st = $pdo->prepare("INSERT INTO form_203_assignments (form_record_id,section_name,position_title,assigned_name,user_id,sort_order) VALUES (?,?,?,?,?,?)");
    foreach ($rows as $r) { $st->execute([$id,$r['section_name'],$r['position_title'],$r['assigned_name'] ?: null,$r['user_id'] ?: null,$r['sort_order']]); }
    return $id;
}

$rows = $incidentId > 0 ? ics203_rows($pdo, $incidentId) : [];
$isComplete = $incidentId > 0 && ics203_has_ic($pdo, $incidentId);
if ($isComplete && $formRecordId <= 0) {
    try { $formRecordId = ics203_ensure_record($pdo, $incidentId, $rows); $formRecord = get_form_record($incidentId, 'ICS203'); } catch (Throwable $e) { $error = $e->getMessage(); }
}


$canSubmitIcs203 = $incidentId > 0 && current_user_can_submit_ics203($pdo, $incidentId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0 && ($_POST['action'] ?? '') === 'submit_ics203') {
    if (!$canSubmitIcs203) {
        http_response_code(403);
        render_header('ICS 203');
        echo '<div class="card"><p>Forbidden</p></div>';
        render_footer();
        exit;
    }
    try {
        if (!$isComplete) {
            throw new RuntimeException('ICS 203 cannot be submitted until an Incident Commander is assigned.');
        }
        $formRecordId = ics203_ensure_record($pdo, $incidentId, $rows);
        $submitterName = (string)($user['display_name'] ?? $user['real_name'] ?? $user['username'] ?? '');
        try {
            $pdo->prepare("UPDATE form_records SET status='submitted', prepared_by_user_id=?, prepared_at=COALESCE(prepared_at,NOW()), updated_at=NOW() WHERE id=?")
                ->execute([(int)($user['id'] ?? 0), $formRecordId]);
        } catch (Throwable $e) {}
        audit_log((int)($user['id'] ?? 0), 'submit', 'ICS203', $formRecordId, 'Submitted ICS 203 by '.$submitterName);
        flash('success', 'ICS 203 submitted.');
        header('Location: '.app_url('/forms/ics203/index.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}


$isPrintView = (string)($_GET['view'] ?? '') === 'print';
render_header($isPrintView ? 'ICS 203 - Organization Assignment List Print View' : 'ICS 203 - Organization Assignment List');
?>
<div class="card no-print">
  <h2>ICS203 - Organization Assignment List</h2>
  <?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
  <p><strong>Status:</strong> <?= $isComplete ? 'Complete' : 'Incomplete' ?></p>
  <p class="small">ICS 203 is auto-populated from active incident position assignments. It is complete when an Incident Commander is assigned. Blank positions are intentionally left blank and cannot be manually typed here.</p>
</div>
<div class="card">
  <h2>ICS203 - Organization Assignment List</h2>
  <p><a href="help.php">Help / Instructions</a></p>
  <table>
    <tr><th>Section</th><th>Position</th><th>Assigned Name</th></tr>
    <?php foreach($rows as $r): ?>
      <tr><td><?=h($r['section_name'])?></td><td><?=h($r['position_title'])?></td><td><?=h($r['assigned_name'])?></td></tr>
    <?php endforeach; ?>
  </table>
</div>


<form method="post">
    <?= csrf_field() ?>
<?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS203',
        'form_title' => 'ICS203 - Organization Assignment List',
        'permissions' => $faPerms,
        'status' => $formStatus ?? ($formRecord['status'] ?? 'draft'),
        'is_final' => in_array(strtolower((string)($formStatus ?? ($formRecord['status'] ?? 'draft'))), ['submitted','approved','complete'], true),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
<?php render_footer(); ?>
