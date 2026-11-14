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

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS205A', $incidentId) : get_form_permission_defaults('ICS205A');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS205A') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS205A') : ['name' => 'TBD', 'position_title' => 'CUL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 205A - Communications List');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

// Signed or approved: locked until someone revises it (includes/form_lock.php).
require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
cb_form_block_locked_post('ICS205A', (int)($incidentId ?? 0), (int)($opId ?? 0), (int)($formRecordId ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0) {
    if (!$canEdit) {
        $error = 'You do not have permission to edit ICS 205A.';
    } else {
    try {
        $formRecordId = get_or_create_form_record($incidentId, 'ICS205A', '205A', (int)(current_user()['id'] ?? 0));
        $stmt = $pdo->prepare("SELECT id FROM form_205a_headers WHERE form_record_id = ? LIMIT 1");
        $stmt->execute([$formRecordId]);
        $headerId = (int)($stmt->fetch()['id'] ?? 0);

        if ($headerId === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO form_205a_headers (form_record_id, sensitivity_notice, notes)
                VALUES (?, 1, NULL)
            ");
            $stmt->execute([$formRecordId]);
            $headerId = (int)$pdo->lastInsertId();
        }

        apply_form_preparer_defaults($formRecordId, resolve_form_preparer($incidentId, 'ICS205A'));

        $action = (string)($_POST['form_action'] ?? 'save');

        if ($action === 'submit') {
            if ($formStatus === 'submitted') {
                throw new RuntimeException('ICS 205A has already been submitted.');
            }
            submit_form_record($formRecordId, 'ICS205A', $incidentId, (int)(current_user()['id'] ?? 0));
            flash('success', 'ICS 205A submitted and digitally signed.');
        } else {
            if ($formStatus === 'submitted') {
                throw new RuntimeException('ICS 205A is already submitted and can no longer be edited.');
            }

            $contactMethods = trim(implode(' | ', array_filter([
                trim($_POST['phone'] ?? ''),
                trim($_POST['email'] ?? '')
            ])));

            $stmt = $pdo->prepare("
                INSERT INTO form_205a_entries
                (header_id, incident_assigned_position, person_name, contact_methods, display_order, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([
                $headerId,
                trim($_POST['position_title'] ?? ''),
                trim($_POST['contact_name'] ?? ''),
                $contactMethods ?: null
            ]);

            flash('success', 'ICS 205A draft entry saved.');
        }

        header('Location: ' . app_url('/forms/ics205a/index.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    }
}

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS205A', $incidentId) : get_form_permission_defaults('ICS205A');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS205A') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS205A') : ['name' => 'TBD', 'position_title' => 'CUL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 205A - Communications List');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}
$canSubmit = $incidentId > 0 && !cb_form_is_locked_status($formStatus) && !empty($permissions['can_submit_final']);

$rows = [];
if ($incidentId > 0) {
    $stmt = $pdo->prepare("
        SELECT
          e.person_name AS contact_name,
          e.incident_assigned_position AS position_title,
          e.contact_methods
        FROM form_205a_entries e
        INNER JOIN form_205a_headers h ON h.id = e.header_id
        INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE e.deleted_at IS NULL
          AND fr.incident_id = ?
          AND fr.form_type = 'ICS205A'
          AND fr.is_deleted = 0
        ORDER BY e.id DESC
    ");
    $stmt->execute([$incidentId]);
    $rows = $stmt->fetchAll();
}

render_header('ICS 205A - Communications List');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
<?php cb_form_status_banner('ICS205A', (int)$incidentId, (int)($formRecord['operational_period_id'] ?? (current_operational_period((int)$incidentId)['id'] ?? 0)), (int)$formRecordId,
    ['subtitle' => 'Contact list for the incident (contains personal information)']); ?>

<div class="card">
  <h2>Add Communications Contact</h2>
  <?php if (!$canEdit): ?>
    <p>You have view-only access to this form.</p>
  <?php elseif (cb_form_is_locked_status($formStatus)): ?>
    <p>This form is signed and locked. To add or change contacts, use <strong>Revise</strong> at the top of the form.</p>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save">
    <div class="grid">
      <div><label>Contact Name</label><input name="contact_name" required></div>
      <div><label>Position Title</label><input name="position_title"></div>
      <div><label>Phone</label><input name="phone"></div>
      <div><label>Email</label><input name="email" type="email"></div>
    </div>
    <label>Notes</label>
    <textarea name="notes"></textarea>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS205A',
        'form_title' => 'ICS205A',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Existing Entries</h2>
  <table class="cb-cards">
    <tr><th>Contact</th><th>Position</th><th>Contact Methods</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['contact_name']) ?></td>
        <td><?= e($r['position_title']) ?></td>
        <td><?= e($r['contact_methods']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php render_footer(); ?>
