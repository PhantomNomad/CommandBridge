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

$opId = (int)($_GET['operational_period_id'] ?? 0);
if ($opId <= 0 && $incidentId > 0) {
    $stmt = $pdo->prepare("SELECT id FROM operational_periods WHERE incident_id=? AND deleted_at IS NULL ORDER BY op_start DESC LIMIT 1");
    $stmt->execute([$incidentId]);
    $opId = (int)($stmt->fetch()['id'] ?? 0);
}

$messageId = (int)($_GET['message_id'] ?? 0);
$isNewMessage = isset($_GET['new']) && $_GET['new'] === '1';

$perms = $incidentId > 0 ? get_current_user_form_permissions('ICS213', $incidentId) : get_form_permission_defaults('ICS213');
$canView = !empty($perms['can_view']);
$canEdit = !empty($perms['can_edit']) || !empty($perms['can_create']);

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 213');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

$formRecordId = $incidentId > 0 ? get_or_create_form_record($incidentId, 'ICS213', '213', (int)($user['id'] ?? 0), $opId ?: null) : 0;
$error = null;

function ics213_op_label(PDO $pdo, int $opId): string {
    if ($opId <= 0) return 'No operational period selected';
    try {
        $st = $pdo->prepare("SELECT * FROM operational_periods WHERE id=?");
        $st->execute([$opId]);
        $op = $st->fetch();
        if (!$op) return 'No operational period selected';
        $parts = [];
        if (!empty($op['period_number'])) $parts[] = 'OP ' . (int)$op['period_number'];
        if (!empty($op['period_name'])) $parts[] = (string)$op['period_name'];
        $range = trim((string)($op['op_start'] ?? $op['start_time'] ?? '') . ' to ' . (string)($op['op_end'] ?? $op['end_time'] ?? ''));
        return trim(($parts ? implode(' - ', $parts) . ' | ' : '') . $range);
    } catch (Throwable $e) {
        return 'Operational Period #' . $opId;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $formRecordId > 0) {
    try {
        if ($messageId > 0 && !$isNewMessage) {
            // Existing messages are locked except for reply information.
            $stmt = $pdo->prepare("UPDATE form_213_messages SET reply=?, reply_by=?, reply_position=?, reply_date_time=?, updated_at=NOW() WHERE id=? AND form_record_id=?");
            $stmt->execute([
                trim($_POST['reply'] ?? ''),
                trim($_POST['reply_by'] ?? ''),
                trim($_POST['reply_position'] ?? ''),
                ($_POST['reply_date_time'] ?? '') ?: null,
                $messageId,
                $formRecordId
            ]);
        } else {
            // New message: save the originating message fields. Reply can be added later from the details screen.
            $stmt = $pdo->prepare("INSERT INTO form_213_messages (form_record_id, to_name, from_name, subject, date_time, message, approved_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->execute([
                $formRecordId,
                trim($_POST['to_name'] ?? ''),
                trim($_POST['from_name'] ?? ''),
                trim($_POST['subject'] ?? ''),
                ($_POST['date_time'] ?? '') ?: null,
                trim($_POST['message'] ?? ''),
                trim($_POST['approved_by'] ?? '')
            ]);
        }
        flash('success', 'ICS 213 saved.');
        header('Location: ' . app_url('/forms/ics213/index.php?operational_period_id=' . $opId));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('ICS 213 - General Message');
$record = get_form_record($incidentId, 'ICS213', $opId ?: null);
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS213') : null;
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
render_ics_form_status('ICS213', $record, $preparer, $signature, [
    'official_file' => '/assets/pdf/official/ICS213.pdf',
    'show_pdf' => false,
    'note' => 'ICS 213 General Message may be created by any ICS position. Once saved, the original message is locked and only the reply information can be updated.'
]);

if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<?php
if ($messageId <= 0 && !$isNewMessage):
    $stmt = $pdo->prepare("SELECT * FROM form_213_messages WHERE form_record_id=? ORDER BY created_at DESC, id DESC");
    $stmt->execute([$formRecordId]);
    $msgs = $stmt->fetchAll();
?>
<div class="card">
  <h2>ICS213 - General Message</h2>
  <p>Use this register to create and track general messages for the selected incident and operational period.</p>
  <div class="grid-2">
    <label>Incident Name<input value="<?= e($incident['incident_name'] ?? $incident['name'] ?? '') ?>" readonly></label>
    <label>Operational Period<input value="<?= e(ics213_op_label($pdo, $opId)) ?>" readonly></label>
  </div>
  <?php if ($canEdit): ?>
    <p><a class="btn btn-primary" href="<?= e(app_url('/forms/ics213/index.php?operational_period_id=' . $opId . '&new=1')) ?>">Create New Message</a></p>
  <?php endif; ?>
  <table class="data-table">
    <thead>
      <tr>
        <th>Date/Time</th>
        <th>To</th>
        <th>From</th>
        <th>Subject</th>
        <th>Reply Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$msgs): ?>
        <tr><td colspan="6">No ICS 213 messages have been created for this form record.</td></tr>
      <?php endif; ?>
      <?php foreach ($msgs as $m): ?>
        <tr>
          <td><?= e($m['date_time'] ?? '') ?></td>
          <td><?= e($m['to_name'] ?? '') ?></td>
          <td><?= e($m['from_name'] ?? '') ?></td>
          <td><?= e($m['subject'] ?? '') ?></td>
          <td><?= trim((string)($m['reply'] ?? '')) !== '' ? 'Replied' : 'Pending' ?></td>
          <td><a class="btn" href="<?= e(app_url('/forms/ics213/index.php?operational_period_id=' . $opId . '&message_id=' . (int)$m['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else:
    $msg = [];
    $locked = false;
    if ($messageId > 0 && !$isNewMessage) {
        $stmt = $pdo->prepare("SELECT * FROM form_213_messages WHERE id=? AND form_record_id=?");
        $stmt->execute([$messageId, $formRecordId]);
        $msg = $stmt->fetch() ?: [];
        $locked = true;
    }
    $dateValue = isset($msg['date_time']) && $msg['date_time'] !== null && $msg['date_time'] !== ''
        ? str_replace(' ', 'T', substr((string)$msg['date_time'], 0, 16))
        : date('Y-m-d\TH:i');
    $replyDateValue = isset($msg['reply_date_time']) && $msg['reply_date_time'] !== null && $msg['reply_date_time'] !== ''
        ? str_replace(' ', 'T', substr((string)$msg['reply_date_time'], 0, 16))
        : '';
?>
<div class="card">
  <h2><?= $locked ? 'ICS 213 Message Details' : 'Create ICS 213 General Message' ?></h2>
  <p><?= $locked ? 'The original message fields are locked. Update the reply information as needed.' : 'Complete the original message fields and save. Reply information can be added later from the message details screen.' ?></p>
  <form method="post" class="cb-lock-exempt">
    <?= csrf_field() ?>
    <div class="grid-2">
      <label>1. Incident Name<input value="<?= e($incident['incident_name'] ?? $incident['name'] ?? '') ?>" readonly></label>
      <label>Operational Period<input value="<?= e(ics213_op_label($pdo, $opId)) ?>" readonly></label>
    </div>

    <h3>Original Message</h3>
    <div class="grid-2">
      <label>2. To<input name="to_name" value="<?= e($msg['to_name'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></label>
      <label>3. From<input name="from_name" value="<?= e($msg['from_name'] ?? ($user['real_name'] ?? $user['display_name'] ?? $user['username'] ?? '')) ?>" <?= $locked ? 'readonly' : '' ?>></label>
    </div>
    <div class="grid-2">
      <label>4. Subject<input name="subject" value="<?= e($msg['subject'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></label>
      <label>5. Date/Time<input type="datetime-local" name="date_time" value="<?= e($dateValue) ?>" <?= $locked ? 'readonly' : '' ?>></label>
    </div>
    <label>6. Message<textarea name="message" rows="6" <?= $locked ? 'readonly' : '' ?>><?= e($msg['message'] ?? '') ?></textarea></label>
    <label>7. Approved By / Signature<input name="approved_by" value="<?= e($msg['approved_by'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></label>

    <?php if ($locked): ?>
      <h3>Reply</h3>
      <label>8. Reply<textarea name="reply" rows="6"><?= e($msg['reply'] ?? '') ?></textarea></label>
      <div class="grid-2">
        <label>Reply By<input name="reply_by" value="<?= e($msg['reply_by'] ?? '') ?>"></label>
        <label>ICS Position<input name="reply_position" value="<?= e($msg['reply_position'] ?? '') ?>"></label>
      </div>
      <label>Reply Date/Time<input type="datetime-local" name="reply_date_time" value="<?= e($replyDateValue) ?>"></label>
    <?php endif; ?>

    <p>
      <a class="btn" href="<?= e(app_url('/forms/ics213/index.php?operational_period_id=' . $opId)) ?>">Back to List</a>
    </p>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'extra_query' => ['message_id' => $messageId ?? 0],
        'form_code' => 'ICS213',
        'form_title' => 'ICS213',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
</div>
<?php endif; ?>
<?php render_footer(); ?>
