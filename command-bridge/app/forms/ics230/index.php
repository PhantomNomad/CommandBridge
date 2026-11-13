<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/form_action_buttons.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);

if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$currentOperationalPeriod = $incidentId > 0 ? current_operational_period($incidentId) : null;
$operationalPeriodId = (int)($currentOperationalPeriod['id'] ?? 0);
$error = null;

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS230', $incidentId) : get_form_permission_defaults('ICS230');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']);
$formRecord = ($incidentId > 0 && $operationalPeriodId > 0) ? get_form_record($incidentId, 'ICS230', $operationalPeriodId) : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS230') : ['name' => 'TBD', 'position_title' => 'SITL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 230 - Daily Meeting Schedule');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0) {
    if (!$canEdit) {
        $error = 'You do not have permission to edit ICS 230.';
    } elseif ($operationalPeriodId <= 0) {
        $error = 'Select or create an operational period before editing ICS 230.';
    } else {
        try {
            $action = (string)($_POST['form_action'] ?? 'save');
            $meetingId = (int)($_POST['meeting_id'] ?? 0);

            $formRecordId = get_or_create_form_record($incidentId, 'ICS230', '230', (int)(current_user()['id'] ?? 0), $operationalPeriodId);
            apply_form_preparer_defaults($formRecordId, resolve_form_preparer($incidentId, 'ICS230'));

            if ($action === 'submit') {
                if ($formStatus === 'submitted') {
                    throw new RuntimeException('ICS 230 has already been submitted.');
                }
                submit_form_record($formRecordId, 'ICS230', $incidentId, (int)(current_user()['id'] ?? 0));
                flash('success', 'ICS 230 submitted and digitally signed.');
            } elseif ($action === 'delete_meeting') {
                if ($formStatus === 'submitted') {
                    throw new RuntimeException('ICS 230 is already submitted and can no longer be edited.');
                }
                $stmt = $pdo->prepare("
                    UPDATE operational_period_meetings
                    SET deleted_at = NOW(), updated_at = NOW(), updated_by_user_id = ?
                    WHERE id = ?
                      AND incident_id = ?
                      AND operational_period_id = ?
                      AND deleted_at IS NULL
                ");
                $stmt->execute([
                    current_user()['id'] ?? null,
                    $meetingId,
                    $incidentId,
                    $operationalPeriodId
                ]);
                flash('success', 'Meeting removed from the schedule.');
            } else {
                if ($formStatus === 'submitted') {
                    throw new RuntimeException('ICS 230 is already submitted and can no longer be edited.');
                }

                $meetingTypeId = (int)($_POST['meeting_type_id'] ?? 0);
                $scheduledAtRaw = trim((string)($_POST['scheduled_at'] ?? ''));
                $scheduledAt = $scheduledAtRaw !== '' ? date('Y-m-d H:i:s', strtotime($scheduledAtRaw)) : '';
                if ($meetingTypeId <= 0) {
                    throw new RuntimeException('Meeting type is required.');
                }
                if ($scheduledAt === '' || $scheduledAt === '1970-01-01 00:00:00') {
                    throw new RuntimeException('Scheduled date/time is required.');
                }

                $title = trim((string)($_POST['title'] ?? ''));
                if ($title === '') {
                    $typeStmt = $pdo->prepare("SELECT name FROM meeting_types WHERE id = ? LIMIT 1");
                    $typeStmt->execute([$meetingTypeId]);
                    $title = (string)(($typeStmt->fetch())['name'] ?? 'Meeting');
                }

                $params = [
                    $incidentId,
                    $operationalPeriodId,
                    $meetingTypeId,
                    $title,
                    $scheduledAt,
                    trim((string)($_POST['location'] ?? '')) ?: null,
                    trim((string)($_POST['purpose'] ?? '')) ?: null,
                    trim((string)($_POST['chair_name'] ?? '')) ?: null,
                    trim((string)($_POST['chair_position_title'] ?? '')) ?: null,
                    trim((string)($_POST['attendees_summary'] ?? '')) ?: null,
                    trim((string)($_POST['meeting_status'] ?? 'scheduled')) ?: 'scheduled',
                    (int)($_POST['display_order'] ?? 0),
                    trim((string)($_POST['notes'] ?? '')) ?: null,
                    current_user()['id'] ?? null,
                    current_user()['id'] ?? null,
                ];

                if ($meetingId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE operational_period_meetings
                        SET meeting_type_id = ?,
                            title = ?,
                            scheduled_at = ?,
                            location = ?,
                            purpose = ?,
                            chair_name = ?,
                            chair_position_title = ?,
                            attendees_summary = ?,
                            status = ?,
                            display_order = ?,
                            notes = ?,
                            updated_by_user_id = ?,
                            updated_at = NOW()
                        WHERE id = ?
                          AND incident_id = ?
                          AND operational_period_id = ?
                          AND deleted_at IS NULL
                    ");
                    $stmt->execute([
                        $meetingTypeId,
                        $title,
                        $scheduledAt,
                        trim((string)($_POST['location'] ?? '')) ?: null,
                        trim((string)($_POST['purpose'] ?? '')) ?: null,
                        trim((string)($_POST['chair_name'] ?? '')) ?: null,
                        trim((string)($_POST['chair_position_title'] ?? '')) ?: null,
                        trim((string)($_POST['attendees_summary'] ?? '')) ?: null,
                        trim((string)($_POST['meeting_status'] ?? 'scheduled')) ?: 'scheduled',
                        (int)($_POST['display_order'] ?? 0),
                        trim((string)($_POST['notes'] ?? '')) ?: null,
                        current_user()['id'] ?? null,
                        $meetingId,
                        $incidentId,
                        $operationalPeriodId
                    ]);
                    flash('success', 'Meeting updated.');
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO operational_period_meetings (
                            incident_id,
                            operational_period_id,
                            meeting_type_id,
                            title,
                            scheduled_at,
                            location,
                            purpose,
                            chair_name,
                            chair_position_title,
                            attendees_summary,
                            status,
                            display_order,
                            notes,
                            created_by_user_id,
                            updated_by_user_id,
                            created_at,
                            updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute($params);
                    flash('success', 'Meeting added to the schedule.');
                }

                $pdo->prepare("UPDATE form_records SET updated_at = NOW() WHERE id = ?")->execute([$formRecordId]);
            }

            header('Location: ' . app_url('/forms/ics230/index.php?op_id=' . $operationalPeriodId));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS230', $incidentId) : get_form_permission_defaults('ICS230');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']);
$formRecord = ($incidentId > 0 && $operationalPeriodId > 0) ? get_form_record($incidentId, 'ICS230', $operationalPeriodId) : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS230') : ['name' => 'TBD', 'position_title' => 'SITL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$canSubmit = $incidentId > 0 && $operationalPeriodId > 0 && $formStatus !== 'submitted' && !empty($permissions['can_submit_final']);

$meetingTypes = get_meeting_types_catalog();
$hasMeetingTypes = false;
foreach ($meetingTypes as $meetingTypeRow) {
    if ((int)($meetingTypeRow['id'] ?? 0) > 0) {
        $hasMeetingTypes = true;
        break;
    }
}
$meetings = [];
$editingMeeting = null;

if ($incidentId > 0 && $operationalPeriodId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            m.*,
            COALESCE(mt.name, '') AS meeting_type_name
        FROM operational_period_meetings m
        LEFT JOIN meeting_types mt ON mt.id = m.meeting_type_id
        WHERE m.incident_id = ?
          AND m.operational_period_id = ?
          AND m.deleted_at IS NULL
        ORDER BY m.scheduled_at ASC, m.display_order ASC, m.id ASC
    ");
    $stmt->execute([$incidentId, $operationalPeriodId]);
    $meetings = $stmt->fetchAll();

    $editId = (int)($_GET['edit_meeting_id'] ?? 0);
    if ($editId > 0) {
        foreach ($meetings as $meetingRow) {
            if ((int)$meetingRow['id'] === $editId) {
                $editingMeeting = $meetingRow;
                break;
            }
        }
    }
}

render_header('ICS 230 - Daily Meeting Schedule');
?>
<div class="card">
  <h2>ICS230 - Daily Meeting Schedule</h2>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <p><strong>Status:</strong> <span class="status-pill"><?= e(ucfirst($formStatus)) ?></span></p>
  <?php if ($currentOperationalPeriod): ?>
    <p><strong>Operational Period:</strong> <?= e(operational_period_label($currentOperationalPeriod)) ?></p>
  <?php else: ?>
    <p class="muted">No operational period is currently available.</p>
  <?php endif; ?>
  <p><strong>Prepared By:</strong> <?= e((string)($preparer['name'] ?? 'TBD')) ?> (<?= e((string)($preparer['position_title'] ?? 'SITL')) ?>)</p>
  <?php if ($signature): ?>
    <p><strong>Digitally Signed By:</strong> <?= e($signature['signer_name']) ?> (<?= e($signature['signer_position_title']) ?>) on <?= e($signature['signed_at']) ?></p>
  <?php else: ?>
    <p class="small">This form remains a live draft until submitted.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Meeting Schedule</h2>
  <p>The selected operational period drives the meeting schedule displayed on this form.</p>
  <?php if (!$currentOperationalPeriod): ?>
    <p class="muted">Create an operational period before using ICS 230.</p>
  <?php elseif (!$hasMeetingTypes): ?>
    <p class="muted">No active meeting types are configured in the database yet.</p>
  <?php elseif ($canEdit && $formStatus !== 'submitted'): ?>
    <form method="post">
    <?= csrf_field() ?>
      <input type="hidden" name="meeting_id" value="<?= e((string)($editingMeeting['id'] ?? 0)) ?>">
      <input type="hidden" name="op_id" value="<?= e((string)$operationalPeriodId) ?>">
      <div class="form-grid">
        <div>
          <label>Meeting Type</label>
          <select name="meeting_type_id" required>
            <option value="">Select a meeting type</option>
            <?php foreach ($meetingTypes as $type): ?>
              <?php $typeId = (int)($type['id'] ?? 0); ?>
              <option value="<?= e((string)$typeId) ?>" <?= ((int)($editingMeeting['meeting_type_id'] ?? 0) === $typeId) ? 'selected' : '' ?>>
                <?= e((string)($type['name'] ?? $type['code'] ?? 'Meeting')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Scheduled Date/Time</label>
          <div class="govai-dt-wrap" data-govai-datetime>
            <input class="govai-dt-display" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" name="scheduled_at" required value="<?= e(format_datetime_local_input((string)($editingMeeting['scheduled_at'] ?? ($currentOperationalPeriod['op_start'] ?? '')))) ?>">
            <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
          </div>
        </div>
        <div>
          <label>Meeting Title</label>
          <input name="title" value="<?= e((string)($editingMeeting['title'] ?? '')) ?>" placeholder="Defaults to meeting type name if blank">
        </div>
        <div>
          <label>Location</label>
          <input name="location" value="<?= e((string)($editingMeeting['location'] ?? '')) ?>">
        </div>
        <div class="full-width">
          <label>Purpose</label>
          <textarea name="purpose"><?= e((string)($editingMeeting['purpose'] ?? '')) ?></textarea>
        </div>
        <div>
          <label>Chair Name</label>
          <input name="chair_name" value="<?= e((string)($editingMeeting['chair_name'] ?? '')) ?>">
        </div>
        <div>
          <label>Chair Position/Title</label>
          <input name="chair_position_title" value="<?= e((string)($editingMeeting['chair_position_title'] ?? '')) ?>">
        </div>
        <div class="full-width">
          <label>Attendees Summary</label>
          <textarea name="attendees_summary"><?= e((string)($editingMeeting['attendees_summary'] ?? '')) ?></textarea>
        </div>
        <div>
          <label>Status</label>
          <select name="meeting_status">
            <?php foreach (['scheduled' => 'Scheduled', 'tentative' => 'Tentative', 'completed' => 'Completed (meeting was held)', 'cancelled' => 'Cancelled'] as $statusOption => $statusLabel): ?>
              <option value="<?= e($statusOption) ?>" <?= ((string)($editingMeeting['status'] ?? 'scheduled') === $statusOption) ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="small">Mark a meeting Completed once it has been held: only completed meetings show as &ldquo;Meeting held&rdquo; on everyone&rsquo;s ICS 214.</div>
        </div>
        <div>
          <label>Display Order</label>
          <input type="number" name="display_order" value="<?= e((string)($editingMeeting['display_order'] ?? 0)) ?>">
        </div>
        <div class="full-width">
          <label>Notes</label>
          <textarea name="notes"><?= e((string)($editingMeeting['notes'] ?? '')) ?></textarea>
        </div>
      </div>
      <div style="margin-top:16px;" class="table-actions">
        <button type="submit" name="form_action" value="save"><?= $editingMeeting ? 'Update Meeting' : 'Add Meeting' ?></button>
        <?php if ($editingMeeting): ?>
          <a class="btn btn-secondary" href="<?= e(app_url('/forms/ics230/index.php?op_id=' . $operationalPeriodId)) ?>">Cancel Edit</a>
        <?php endif; ?>
      </div>
    
</form>
  <?php endif; ?>

  
  <?php if ($currentOperationalPeriod): ?>
    <?php
    render_ics_form_actions([
        'form_code' => 'ICS230',
        'form_title' => 'ICS230',
        'permissions' => ['can_view' => $canView],
        'incident_id' => $incidentId,
        'op_id' => $operationalPeriodId,
        'show_submit' => false,
    ]);
    ?>
    <div class="table-actions no-print" style="margin: 0 0 16px;">
      <a class="btn btn-secondary" href="<?= e(app_url('/forms/ics230/pdf.php?mode=posting&op_id=' . $operationalPeriodId . '&preview=1')) ?>" target="_blank" rel="noopener">Preview Meeting Posting PDF</a>
      <a class="btn btn-secondary" href="<?= e(app_url('/forms/ics230/pdf.php?mode=posting&op_id=' . $operationalPeriodId)) ?>" target="_blank" rel="noopener">Download Meeting Posting PDF</a>
    </div>
  <?php endif; ?>

  <table style="margin-top:20px;">
    <tr>
      <th style="width:150px;">Scheduled</th>
      <th style="width:180px;">Meeting Type</th>
      <th>Title / Purpose</th>
      <th style="width:180px;">Location / Chair</th>
      <th style="width:110px;">Status</th>
      <?php if ($canEdit && $formStatus !== 'submitted'): ?><th style="width:150px;">Actions</th><?php endif; ?>
    </tr>
    <?php if (!$meetings): ?>
      <tr><td colspan="<?= ($canEdit && $formStatus !== 'submitted') ? '6' : '5' ?>">No meetings scheduled for this operational period.</td></tr>
    <?php else: ?>
      <?php foreach ($meetings as $meeting): ?>
        <tr>
          <td><?= e(format_datetime_display((string)$meeting['scheduled_at'])) ?></td>
          <td><?= e((string)($meeting['meeting_type_name'] ?: 'Meeting')) ?></td>
          <td>
            <strong><?= e((string)$meeting['title']) ?></strong>
            <?php if (!empty($meeting['purpose'])): ?><div class="small"><?= nl2br(e((string)$meeting['purpose'])) ?></div><?php endif; ?>
            <?php if (!empty($meeting['attendees_summary'])): ?><div class="small">Attendees: <?= nl2br(e((string)$meeting['attendees_summary'])) ?></div><?php endif; ?>
          </td>
          <td>
            <?= e((string)($meeting['location'] ?? '')) ?>
            <?php if (!empty($meeting['chair_name'])): ?>
              <div class="small">Chair: <?= e((string)$meeting['chair_name']) ?><?php if (!empty($meeting['chair_position_title'])): ?> (<?= e((string)$meeting['chair_position_title']) ?>)<?php endif; ?></div>
            <?php endif; ?>
          </td>
          <td><?= e(ucfirst((string)$meeting['status'])) ?></td>
          <?php if ($canEdit && $formStatus !== 'submitted'): ?>
            <td>
              <div class="table-actions">
                <a class="btn btn-secondary" href="<?= e(app_url('/forms/ics230/index.php?op_id=' . $operationalPeriodId . '&edit_meeting_id=' . (int)$meeting['id'])) ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Remove this meeting from the schedule?');">
    <?= csrf_field() ?>
                  <input type="hidden" name="meeting_id" value="<?= e((string)$meeting['id']) ?>">
                  <input type="hidden" name="op_id" value="<?= e((string)$operationalPeriodId) ?>">
                  <button type="submit" name="form_action" value="delete_meeting" class="btn btn-secondary">Delete</button>
                </form>
              </div>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>
</div>


<?php render_footer(); ?>
