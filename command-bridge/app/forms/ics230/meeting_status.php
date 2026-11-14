<?php
declare(strict_types=1);
/*
 * Record whether an ICS 230 meeting was held: mark it completed or cancelled.
 * Opened from the "Was this meeting held?" dashboard reminder and from the ICS 230 meeting list.
 * Works after the ICS 230 is signed, and for meetings in earlier operational periods.
 */
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/meetings.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$meetingId = (int)($_POST['meeting_id'] ?? ($_GET['meeting_id'] ?? 0));
$meeting = cb_meeting_load($incidentId, $meetingId);
$back = app_url('/forms/ics230/index.php');

if (!$meeting) {
    render_header('Was this meeting held?', 'forms/ics230/index');
    echo '<div class="card"><p>That meeting could not be found. It may have been removed from the schedule.</p>'
        . '<p><a class="btn btn-secondary" href="' . e($back) . '">Open the ICS 230</a></p></div>';
    render_footer();
    exit;
}

$canSet = cb_meeting_user_can_set_status($incidentId, $meeting);
$isOpen = in_array(strtolower((string)$meeting['status']), CB_MEETING_OPEN_STATUSES, true);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = (string)($_POST['meeting_status'] ?? '');
    if (!$canSet) {
        $error = 'You do not have permission to update this meeting.';
    } elseif (!cb_meeting_set_status($incidentId, $meeting, $status, (string)($_POST['status_note'] ?? ''))) {
        $error = 'Choose Completed or Cancelled.';
    } else {
        flash('success', 'Meeting marked ' . ($status === 'completed' ? 'completed (held).' : 'cancelled.'));
        $returnTo = (string)($_POST['return_to'] ?? '');
        header('Location: ' . ($returnTo === 'ics230' ? $back : app_url('/dashboard/index.php')));
        exit;
    }
}

render_header('Was this meeting held?', 'forms/ics230/index');
?>
<div class="card">
  <h2><?= e((string)$meeting['title']) ?></h2>
  <?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>
  <table class="table">
    <tr><th>Scheduled</th><td><?= e(format_datetime_display((string)$meeting['scheduled_at'])) ?></td></tr>
    <tr><th>Type</th><td><?= e((string)($meeting['meeting_type_name'] ?: 'Meeting')) ?></td></tr>
    <?php if (!empty($meeting['period_number'])): ?><tr><th>Operational period</th><td><?= e((string)$meeting['period_number']) ?></td></tr><?php endif; ?>
    <?php if (!empty($meeting['location'])): ?><tr><th>Location</th><td><?= e((string)$meeting['location']) ?></td></tr><?php endif; ?>
    <?php if (!empty($meeting['chair_name'])): ?><tr><th>Chair</th><td><?= e((string)$meeting['chair_name']) ?><?= !empty($meeting['chair_position_title']) ? ' (' . e((string)$meeting['chair_position_title']) . ')' : '' ?></td></tr><?php endif; ?>
    <tr><th>Status</th><td><?= e(ucfirst((string)$meeting['status'])) ?></td></tr>
  </table>

  <?php if (!$isOpen): ?>
    <p>This meeting is already marked <strong><?= e((string)$meeting['status']) ?></strong>. Nothing more to do.</p>
  <?php elseif (!$canSet): ?>
    <p>Only the chair, the Incident Commander, the Planning Section Chief or someone who can edit the ICS 230 can record whether this meeting was held.</p>
  <?php else: ?>
    <p>Record whether this meeting took place. Only completed meetings are shown as "Meeting held" on everyone's ICS 214.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="meeting_id" value="<?= e((string)$meeting['id']) ?>">
      <input type="hidden" name="return_to" value="<?= e((string)($_GET['return_to'] ?? '')) ?>">
      <label for="status_note">Note (optional)</label>
      <input id="status_note" name="status_note" maxlength="500" placeholder="For example: held at 1415, or: postponed to OP 3">
      <p class="form-actions">
        <button type="submit" class="btn btn-primary" name="meeting_status" value="completed">Completed (meeting was held)</button>
        <button type="submit" class="btn btn-secondary" name="meeting_status" value="cancelled">Cancelled</button>
      </p>
    </form>
  <?php endif; ?>
  <p><a href="<?= e($back) ?>">Open the ICS 230</a></p>
</div>
<?php render_footer(); ?>
