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
$error = null;

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS207', $incidentId) : get_form_permission_defaults('ICS207');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS207') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS207') : ['name' => 'TBD', 'position_title' => 'DOCL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 207 - Organization Assignment List');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

$rows = [];
if ($incidentId > 0) {
    $stmt = $pdo->prepare("
        SELECT
          u.real_name,
          r.role_code AS code,
          r.role_name AS name
        FROM incident_positions ip
        INNER JOIN roles r ON r.id = ip.role_id
        LEFT JOIN users u ON u.id = ip.user_id
        WHERE ip.incident_id = ?
          AND ip.deleted_at IS NULL
          AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
        ORDER BY r.role_code, u.real_name
    ");
    $stmt->execute([$incidentId]);
    $rows = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0) {
    if (!$canEdit) {
        $error = 'You do not have permission to edit ICS 207.';
    } else {
    try {
        $formRecordId = get_or_create_form_record($incidentId, 'ICS207', '207', (int)(current_user()['id'] ?? 0));
        apply_form_preparer_defaults($formRecordId, resolve_form_preparer($incidentId, 'ICS207'));

        if ($formStatus === 'submitted') {
            throw new RuntimeException('ICS 207 has already been submitted.');
        }

        $json = json_encode($rows, JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("SELECT id FROM form_207_snapshots WHERE form_record_id = ? LIMIT 1");
        $stmt->execute([$formRecordId]);
        $snapshotId = (int)($stmt->fetch()['id'] ?? 0);

        if ($snapshotId > 0) {
            $stmt = $pdo->prepare("UPDATE form_207_snapshots SET generated_json = ?, created_at = NOW() WHERE id = ?");
            $stmt->execute([$json, $snapshotId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO form_207_snapshots (form_record_id, generated_json, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$formRecordId, $json]);
        }

        submit_form_record($formRecordId, 'ICS207', $incidentId, (int)(current_user()['id'] ?? 0));
        flash('success', 'ICS 207 submitted and digitally signed.');
        header('Location: ' . app_url('/forms/ics207/index.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    }
}

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS207', $incidentId) : get_form_permission_defaults('ICS207');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS207') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS207') : ['name' => 'TBD', 'position_title' => 'DOCL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 207 - Organization Assignment List');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}
$canSubmit = $incidentId > 0 && $formStatus !== 'submitted' && !empty($permissions['can_submit_final']);

render_header('ICS 207 - Organization Assignment List');
?>
<div class="card">
  <h2>ICS207 - Organization Chart</h2>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <p><strong>Status:</strong> <?= e(ucfirst($formStatus)) ?></p>
  <p><strong>Prepared By:</strong> <?= e((string)($preparer['name'] ?? 'TBD')) ?> (<?= e((string)($preparer['position_title'] ?? 'DOCL')) ?>)</p>
  <?php if ($signature): ?>
    <p><strong>Digitally Signed By:</strong> <?= e($signature['signer_name']) ?> (<?= e($signature['signer_position_title']) ?>) on <?= e($signature['signed_at']) ?></p>
  <?php else: ?>
    <p class="small">This form remains a live draft until submitted.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Current Assignments</h2>
  <p>This starter page provides the initial organization view generated from assigned incident users and roles.</p>
  <table>
    <tr><th>Role Code</th><th>Role Name</th><th>Assigned Person</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['code']) ?></td>
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['real_name']) ?></td>
      </tr>
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
        'form_code' => 'ICS207',
        'form_title' => 'ICS207 - Organization Chart',
        'permissions' => $faPerms,
        'status' => $formStatus ?? ($formRecord['status'] ?? 'draft'),
        'is_final' => in_array(strtolower((string)($formStatus ?? ($formRecord['status'] ?? 'draft'))), ['submitted','approved','complete'], true),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
<?php render_footer(); ?>
